<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Media;
use App\Models\User;
use App\Notifications\AlbumExportReady;
use App\Services\Album\Album;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * "Download all" (plan section 11): every guest photo and video of an event,
 * hidden ones included (the host's own copy), in one ZIP on the media disk at
 * events/{event_id}/exports/. The host gets an email with a signed link that
 * expires (config album.export_days) and a notice on the album page.
 */
class ExportAlbumZip implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public string $eventId, public ?string $userId = null) {}

    public function handle(Album $album): void
    {
        $event = Event::query()->findOrFail($this->eventId);
        $disk = $album->disk();
        $dir = rtrim(sys_get_temp_dir(), '/').'/daawa-zip-'.Str::random(12);
        File::ensureDirectoryExists($dir, 0700);
        $zipPath = "{$dir}/album.zip";

        try {
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create the album ZIP.');
            }

            $count = 0;
            Media::query()->album()->where('event_id', $event->getKey())->with('household')->oldest()
                ->each(function (Media $media) use ($disk, $zip, $dir, &$count) {
                    if (! $disk->exists($media->storage_key)) {
                        return;
                    }
                    // stream through a temp file: originals can be large videos
                    $local = "{$dir}/".$media->getKey();
                    $in = $disk->readStream($media->storage_key);
                    $out = fopen($local, 'w');
                    stream_copy_to_stream($in, $out);
                    fclose($out);
                    is_resource($in) && fclose($in);

                    $zip->addFile($local, static::entryName($media, ++$count));
                    // photos are already compressed: store them as they are
                    $zip->setCompressionName(static::entryName($media, $count), ZipArchive::CM_STORE);
                });
            $zip->close();

            $name = 'album-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.zip';
            $key = "events/{$event->getKey()}/exports/{$name}";
            $stream = fopen($zipPath, 'r');
            $disk->writeStream($key, $stream);
            is_resource($stream) && fclose($stream);

            // older exports of this event
            foreach ($disk->files("events/{$event->getKey()}/exports") as $file) {
                if ($file !== $key) {
                    $disk->delete($file);
                }
            }

            $url = URL::temporarySignedRoute('album.export.download', now()->addDays((int) config('album.export_days')), ['event' => $event->getKey(), 'file' => $name]);
            $album->update($event, ['export' => ['status' => 'ready', 'at' => now()->toIso8601String(), 'url' => $url, 'count' => $count]]);

            $user = $this->userId ? User::query()->find($this->userId) : $event->owner;
            $user?->notify(new AlbumExportReady($event, $url, $count));
        } catch (Throwable $e) {
            $album->update($event, ['export' => ['status' => 'failed', 'at' => now()->toIso8601String()]]);
            throw $e;
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** "0007-khalid-family.jpg": in upload order, with who sent it. */
    public static function entryName(Media $media, int $n): string
    {
        $who = Str::slug((string) ($media->uploader_name ?? $media->household?->display_name ?? ''), '-', 'ar') ?: 'guest';
        $extension = pathinfo($media->storage_key, PATHINFO_EXTENSION) ?: 'bin';

        return sprintf('%04d-%s.%s', $n, mb_substr($who, 0, 40), $extension);
    }
}
