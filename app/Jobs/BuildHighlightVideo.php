<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\HighlightVideo;
use App\Models\Media;
use App\Models\User;
use App\Notifications\HighlightVideoReady;
use App\Services\Album\Album;
use App\Services\Keepsakes\HighlightBuilder;
use App\Services\Keepsakes\HighlightPlan;
use App\Services\Keepsakes\HighlightVideos;
use App\Services\Keepsakes\ThankYouCards;
use App\Services\Keepsakes\TitleCard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds an event's highlight video (phase 8D): picks the album's visible
 * photos spread over the evening (HighlightPlan decides how many and how
 * long), adds up to three guest clips, renders the title card with Chromium,
 * then one MP4 per format with ffmpeg (HighlightBuilder). The files replace
 * the previous build under events/{id}/highlight/ on the media disk, and the
 * host is emailed a signed download link.
 */
class BuildHighlightVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $videoId)
    {
        $this->timeout = (int) config('keepsakes.video.timeout', 900) + 60;
    }

    public function handle(HighlightVideos $videos, HighlightBuilder $builder, TitleCard $titles, Album $album): void
    {
        $video = HighlightVideo::query()->with(['event.invitation', 'musicTrack'])->find($this->videoId);
        if (! $video || $video->status !== 'queued') {
            return;
        }
        $event = $video->event;
        $video->update(['status' => 'rendering', 'started_at' => now(), 'error' => null]);

        $deadline = microtime(true) + (int) config('keepsakes.video.timeout', 900);
        $dir = rtrim(sys_get_temp_dir(), '/').'/daawa-highlight-'.Str::random(12);
        File::ensureDirectoryExists($dir, 0700);
        $disk = $album->disk();

        try {
            $clips = $videos->clips($event);
            $plan = HighlightPlan::make($videos->photos($event)->count(), $clips->count());
            $photos = ThankYouCards::spread($videos->photos($event), $plan['photos']);
            $clips = $clips->take($plan['clips']);

            $local = fn (Media $m, string $key) => $this->download($disk, $key, "{$dir}/".$m->getKey().'.'.(pathinfo($key, PATHINFO_EXTENSION) ?: 'bin'));
            $photoFiles = $photos->map(fn (Media $m) => $local($m, $m->storage_key))->filter()->values();
            $clipFiles = $clips->map(fn (Media $m) => $local($m, $m->storage_key))->filter()->values();

            $music = null;
            if ($video->musicTrack && $disk->exists($video->musicTrack->storage_key)) {
                $music = $this->download($disk, $video->musicTrack->storage_key, "{$dir}/music.".(pathinfo($video->musicTrack->storage_key, PATHINFO_EXTENSION) ?: 'mp3'));
            }

            $directory = $videos->directory($event);
            $outputs = [];
            foreach ($video->formats as $format) {
                $size = config("keepsakes.video.formats.{$format}");
                if (! $size) {
                    continue;
                }
                [$w, $h] = [(int) $size['width'], (int) $size['height']];

                $title = "{$dir}/title-{$format}.png";
                file_put_contents($title, $titles->png($event, $w, $h)['png']);

                $parts = [['type' => 'still', 'path' => $title, 'seconds' => $plan['title'], 'zoom' => 1.04]];
                foreach ($photoFiles as $file) {
                    $parts[] = ['type' => 'still', 'path' => $file, 'seconds' => $plan['photo']];
                }
                foreach ($clipFiles as $file) {
                    $parts[] = ['type' => 'clip', 'path' => $file, 'seconds' => $plan['clip']];
                }
                $parts[] = ['type' => 'still', 'path' => $title, 'seconds' => $plan['title'], 'zoom' => 1.04];

                $out = "{$dir}/{$format}.mp4";
                $info = $builder->build($parts, $w, $h, $music, $out, $dir, $deadline);
                $poster = "{$dir}/{$format}.jpg";
                $builder->poster($out, $plan['title'] + min($plan['photo'], 2.0), $poster, $deadline);

                $stamp = now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
                $key = "{$directory}/{$format}-{$stamp}.mp4";
                $posterKey = "{$directory}/{$format}-{$stamp}.jpg";
                $this->upload($disk, $out, $key);
                $this->upload($disk, $poster, $posterKey);

                $outputs[$format] = ['key' => $key, 'poster' => $posterKey] + $info;
            }

            // the previous build's files
            $keep = collect($outputs)->flatMap(fn (array $o) => [$o['key'], $o['poster']])->all();
            foreach ($disk->files($directory) as $file) {
                if (! in_array($file, $keep, true)) {
                    $disk->delete($file);
                }
            }

            $video->update([
                'status' => 'ready',
                'outputs' => $outputs,
                'photo_count' => $photoFiles->count(),
                'clip_count' => $clipFiles->count(),
                'finished_at' => now(),
            ]);
            AuditLog::record('highlight_video.ready', $event, ['video' => $video->getKey(), 'formats' => array_keys($outputs)]);

            $user = $video->requested_by ? User::query()->find($video->requested_by) : $event->owner;
            $user?->notify(new HighlightVideoReady($video->fresh()));
        } catch (Throwable $e) {
            $video->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
            AuditLog::record('highlight_video.failed', $event, ['video' => $video->getKey(), 'error' => mb_substr($e->getMessage(), 0, 300)]);
            report($e);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private function download(Filesystem $disk, string $key, string $to): ?string
    {
        if (! $disk->exists($key)) {
            return null;
        }
        $in = $disk->readStream($key);
        $out = fopen($to, 'w');
        stream_copy_to_stream($in, $out);
        fclose($out);
        is_resource($in) && fclose($in);

        return $to;
    }

    private function upload(Filesystem $disk, string $from, string $key): void
    {
        $stream = fopen($from, 'r');
        $disk->writeStream($key, $stream);
        is_resource($stream) && fclose($stream);
    }
}
