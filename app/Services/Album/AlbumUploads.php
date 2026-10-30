<?php

namespace App\Services\Album;

use App\Jobs\MakeThumbnails;
use App\Models\GuestbookEntry;
use App\Models\Media;
use App\Services\Digits;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns a guest's file into a media row (plan section 11): type and size
 * checks, the album's total size, the video guestbook add-on, moderation
 * (hidden until the host approves it when "approve first" is on), then
 * MakeThumbnails. Photos arrive compressed by the browser; these limits are
 * the hard stop.
 */
class AlbumUploads
{
    public function __construct(private Album $album) {}

    /**
     * @param  array{duration?: ?float, name?: ?string, guestbook?: ?bool}  $meta
     */
    public function storeFile(AlbumAccess $access, UploadedFile $file, array $meta, ?UploadedFile $poster = null): Media
    {
        $mime = (string) $file->getMimeType();
        [$kind, $extension] = $this->check($access, $mime, (int) $file->getSize(), $meta);

        $size = null;
        if ($kind === 'photo') {
            $size = @getimagesize($file->getRealPath());
            if (! $size) {
                throw ValidationException::withMessages(['file' => __('album.guest.bad_type')]);
            }
        }

        $id = (string) Str::uuid();
        $key = $this->album->path($access->event, $id, $extension);
        $disk = $this->album->disk();
        abort_unless($disk->putFileAs(dirname($key), $file, basename($key)), 500);

        $posterKey = null;
        if ($kind === 'video' && $poster) {
            $posterKey = dirname($key).'/'.$id.'-poster.'.($poster->getMimeType() === 'image/webp' ? 'webp' : 'jpg');
            $disk->putFileAs(dirname($posterKey), $poster, basename($posterKey));
        }

        return $this->record($access, $id, $kind, $mime, $key, (int) $file->getSize(), $meta, $size ? [$size[0], $size[1]] : null, $posterKey);
    }

    /**
     * S3: a signed PUT URL for the browser, plus an encrypted ticket it sends to
     * /uploads/complete afterwards (nothing is recorded before the file exists).
     *
     * @return array{url: string, headers: array, upload: string}
     */
    public function signDirect(AlbumAccess $access, string $mime, int $bytes, array $meta): array
    {
        [$kind, $extension] = $this->check($access, $mime, $bytes, $meta);

        $id = (string) Str::uuid();
        $key = $this->album->path($access->event, $id, $extension);
        ['url' => $url, 'headers' => $headers] = $this->album->disk()->temporaryUploadUrl($key, now()->addMinutes(15), ['ContentType' => $mime]);

        return [
            'url' => $url,
            'headers' => $headers,
            'upload' => Crypt::encryptString(json_encode([
                'id' => $id, 'key' => $key, 'kind' => $kind, 'mime' => $mime, 'token' => $access->token,
                'meta' => $meta, 'exp' => now()->addHour()->getTimestamp(),
            ])),
        ];
    }

    public function completeDirect(AlbumAccess $access, array $ticket): Media
    {
        abort_if(($ticket['token'] ?? null) !== $access->token || ($ticket['exp'] ?? 0) < time(), 422);
        abort_if(Media::query()->whereKey($ticket['id'])->exists(), 409);

        $disk = $this->album->disk();
        abort_unless($disk->exists($ticket['key']), 422);
        $bytes = (int) $disk->size($ticket['key']);

        try {
            $this->check($access, $ticket['mime'], $bytes, $ticket['meta'] ?? []);
        } catch (ValidationException $e) {
            $disk->delete($ticket['key']);
            throw $e;
        }

        return $this->record($access, $ticket['id'], $ticket['kind'], $ticket['mime'], $ticket['key'], $bytes, $ticket['meta'] ?? [], null, null);
    }

    /**
     * @return array{0: string, 1: string} kind, extension
     */
    private function check(AlbumAccess $access, string $mime, int $bytes, array $meta): array
    {
        $event = $access->event;
        [$kind, $extension] = Album::TYPES[$mime] ?? throw ValidationException::withMessages(['file' => __('album.guest.bad_type')]);
        $guestbook = (bool) ($meta['guestbook'] ?? false);

        if ($guestbook) {
            if ($kind !== 'video' || ! $this->album->videoGuestbook($event)) {
                throw ValidationException::withMessages(['file' => __('album.guest.guestbook_video_off')]);
            }
            $limits = config('album.guestbook_video');
        } else {
            $limits = $kind === 'video' ? config('album.video') : ['max_mb' => config('album.photo_max_mb'), 'max_seconds' => null];
        }

        if ($bytes > $limits['max_mb'] * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => __('album.guest.too_large', ['max' => Digits::localize($limits['max_mb'], app()->getLocale())])]);
        }
        if ($kind === 'video' && isset($meta['duration']) && $meta['duration'] > $limits['max_seconds'] + 0.5) {
            throw ValidationException::withMessages(['file' => __('album.guest.too_long', ['max' => Digits::localize($limits['max_seconds'], app()->getLocale())])]);
        }
        if ($this->album->usedBytes($event) + $bytes > $this->album->maxBytes($event)) {
            throw ValidationException::withMessages(['file' => __('album.guest.full')]);
        }

        return [$kind, $extension];
    }

    private function record(AlbumAccess $access, string $id, string $kind, string $mime, string $key, int $bytes, array $meta, ?array $size, ?string $posterKey): Media
    {
        $guestbook = (bool) ($meta['guestbook'] ?? false);
        // "approve before the wall": new uploads wait hidden for the host (guestbook videos are only for the hosts anyway)
        $hidden = $guestbook || $this->album->moderated($access->event);
        $name = $access->household ? null : (trim((string) ($meta['name'] ?? '')) ?: null);

        return DB::transaction(function () use ($access, $id, $kind, $mime, $key, $bytes, $meta, $size, $posterKey, $guestbook, $hidden, $name) {
            $media = new Media([
                'event_id' => $access->event->getKey(),
                'household_id' => $access->household?->getKey(),
                'kind' => $kind,
                'source' => $guestbook ? Media::SOURCE_GUESTBOOK : Media::SOURCE_GUEST,
                'storage_key' => $key,
                // a video's poster frame (from the browser) becomes its thumbnails' source
                'thumb_key' => $posterKey,
                'mime' => $mime,
                'width' => $size[0] ?? null,
                'height' => $size[1] ?? null,
                'bytes' => $bytes,
                'duration' => isset($meta['duration']) ? (int) round((float) $meta['duration']) : null,
                'uploader_name' => $name,
                'hidden' => $hidden,
                'approved_at' => $hidden ? null : now(),
            ]);
            $media->id = $id;
            $media->save();

            if ($guestbook) {
                GuestbookEntry::query()->create([
                    'event_id' => $access->event->getKey(),
                    'household_id' => $access->household?->getKey(),
                    'kind' => 'video',
                    'text' => $name,
                    'media_id' => $media->getKey(),
                ]);
            }

            MakeThumbnails::dispatch($media->getKey())->afterCommit();

            return $media;
        });
    }
}
