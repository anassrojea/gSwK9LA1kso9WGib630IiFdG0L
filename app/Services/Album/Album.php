<?php

namespace App\Services\Album;

use App\Models\Event;
use App\Models\Media;
use App\Services\Entitlements;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The event album and its live photo wall (plan section 11): what is
 * unlocked, the host's settings (events.album), limits, file paths and
 * signed URLs. Guest side: App\Http\Controllers\Invite\AlbumController;
 * host side: App\Http\Controllers\Dashboard\AlbumController.
 *
 * Unlocked by the photo_wall feature (Signature) or a photo cloud add-on
 * (Entitlements "photo_cloud"); the wall itself needs photo_wall.
 */
class Album
{
    public const PHOTO_CLOUD = 'photo_cloud';

    public const VIDEO_GUESTBOOK = 'video_guestbook';

    /** events.album_token: its own length, so /i/{token} can tell it from household (12) and open-link (16) tokens */
    public const TOKEN_LENGTH = 14;

    public const VARIANTS = ['original', 'preview', 'thumb'];

    /** what guests may upload => [kind, extension] */
    public const TYPES = [
        'image/jpeg' => ['photo', 'jpg'],
        'image/png' => ['photo', 'png'],
        'image/webp' => ['photo', 'webp'],
        'video/mp4' => ['video', 'mp4'],
        'video/quicktime' => ['video', 'mov'],
        'video/webm' => ['video', 'webm'],
    ];

    private const DEFAULTS = [
        'upload_before_event' => false,
        'moderate' => false,
        'pin_hash' => null,
        'wall_private' => false,
        'wall_secret' => null,
        'export' => null,
    ];

    public function __construct(private Entitlements $entitlements) {}

    public function enabled(Event $event): bool
    {
        return $this->entitlements->hasFeature($event, Entitlements::PHOTO_WALL)
            || $this->entitlements->hasFeature($event, self::PHOTO_CLOUD);
    }

    public function wallEnabled(Event $event): bool
    {
        return $this->entitlements->hasFeature($event, Entitlements::PHOTO_WALL);
    }

    public function videoGuestbook(Event $event): bool
    {
        return $this->entitlements->hasFeature($event, self::VIDEO_GUESTBOOK);
    }

    /**
     * @return array{upload_before_event: bool, moderate: bool, pin_hash: ?string, wall_private: bool, wall_secret: ?string, export: ?array}
     */
    public function settings(Event $event): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key($event->album ?? [], self::DEFAULTS));
    }

    public function update(Event $event, array $changes): void
    {
        $event->album = array_merge($this->settings($event), array_intersect_key($changes, self::DEFAULTS));
        $event->save();
    }

    /**
     * Guests may upload once the event has started, or any time when the host allows
     * uploads before it; never after the storage period ended.
     */
    public function uploadsOpen(Event $event): bool
    {
        if (! $this->enabled($event)) {
            return false;
        }
        if ($event->storage_until && $event->storage_until->endOfDay()->isPast()) {
            return false;
        }

        return $this->settings($event)['upload_before_event']
            || ! $event->starts_at
            || $event->starts_at->isPast();
    }

    public function moderated(Event $event): bool
    {
        return $this->settings($event)['moderate'];
    }

    /* ---------- PIN ---------- */

    public function hasPin(Event $event): bool
    {
        return $this->settings($event)['pin_hash'] !== null;
    }

    public function setPin(Event $event, ?string $pin): void
    {
        $this->update($event, ['pin_hash' => $pin === null || $pin === '' ? null : Hash::make($pin)]);
    }

    public function verifyPin(Event $event, string $pin): bool
    {
        $hash = $this->settings($event)['pin_hash'];

        return $hash !== null && Hash::check($pin, $hash);
    }

    /**
     * What the browser keeps after the PIN was right (guest pages have no session).
     * Tied to the PIN's hash, so changing the PIN locks everyone out again.
     */
    public function pinKey(Event $event): ?string
    {
        $hash = $this->settings($event)['pin_hash'];

        return $hash === null ? null : substr(hash_hmac('sha256', 'album-pin|'.$event->getKey().'|'.$hash, (string) config('app.key')), 0, 32);
    }

    public function keyValid(Event $event, ?string $key): bool
    {
        $expected = $this->pinKey($event);

        return $expected === null || (is_string($key) && hash_equals($expected, $key));
    }

    /* ---------- links ---------- */

    /**
     * The event's own upload token (/i/{album_token}/album), made on first use.
     */
    public function albumToken(Event $event): string
    {
        if ($event->album_token) {
            return $event->album_token;
        }

        do {
            $token = Str::random(self::TOKEN_LENGTH);
        } while (Event::query()->where('album_token', $token)->exists());

        $event->forceFill(['album_token' => $token])->save();

        return $token;
    }

    /**
     * The upload link for the hall screen's QR and the host: the open link when it
     * is on, else the album's own link.
     */
    public function uploadUrl(Event $event): string
    {
        $link = $event->openLink;
        $token = $link?->enabled ? $link->token : $this->albumToken($event);

        return $this->inviteUrl(route('album.show', $token, false));
    }

    public function wallSecret(Event $event): string
    {
        $secret = $this->settings($event)['wall_secret'];
        if (! $secret) {
            $secret = Str::random(20);
            $this->update($event, ['wall_secret' => $secret]);
        }

        return $secret;
    }

    public function wallKeyValid(Event $event, ?string $key): bool
    {
        $settings = $this->settings($event);

        return ! $settings['wall_private']
            || ($settings['wall_secret'] && is_string($key) && hash_equals($settings['wall_secret'], $key));
    }

    /**
     * The wall's address for the hall screen (with its secret when the wall is private).
     */
    public function wallUrl(Event $event): ?string
    {
        $slug = $event->invitation?->slug;
        if (! $slug) {
            return null;
        }

        $query = $this->settings($event)['wall_private'] ? '?k='.$this->wallSecret($event) : '';

        return $this->inviteUrl(route('wall.show', $slug, false)).$query;
    }

    public function inviteUrl(string $path): string
    {
        return rtrim((string) config('app.invite_url'), '/').$path;
    }

    /* ---------- storage ---------- */

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function diskName(): string
    {
        return (string) config('filesystems.media_disk', 'public');
    }

    /** S3-compatible storage: the browser uploads straight to it with a signed URL. */
    public function directUploads(): bool
    {
        return config("filesystems.disks.{$this->diskName()}.driver") === 's3';
    }

    public function path(Event $event, string $id, string $extension): string
    {
        return "events/{$event->getKey()}/album/{$id}.{$extension}";
    }

    public function thumbPath(Media $media, int $size): string
    {
        return "events/{$media->event_id}/album/thumbs/{$media->getKey()}-{$size}.".static::thumbExtension();
    }

    /** Thumbnails are WebP; a PHP whose GD cannot write WebP makes JPEGs instead. */
    public static function thumbExtension(): string
    {
        return function_exists('imagewebp') && (imagetypes() & IMG_WEBP) ? 'webp' : 'jpg';
    }

    public function maxBytes(Event $event): int
    {
        $limits = config('album.max_mb');
        $plan = $event->plan_id ?? $event->organization?->plan_id ?? Entitlements::FALLBACK_PLAN;
        $mb = $limits[$plan] ?? $limits['default'];
        if ($this->entitlements->hasFeature($event, self::PHOTO_CLOUD)) {
            $mb = max($mb, $limits['photo_cloud']);
        }

        return (int) $mb * 1024 * 1024;
    }

    public function usedBytes(Event $event): int
    {
        return (int) Media::query()->where('event_id', $event->getKey())->whereIn('source', [Media::SOURCE_GUEST, Media::SOURCE_GUESTBOOK])->sum('bytes');
    }

    /**
     * A URL for one file of an upload. On S3 a temporary URL; on the local disk a
     * signed, expiring route (relative, so it works on the guest domain too).
     * $all: the host's own pages, which also show hidden items.
     */
    public function url(Media $media, string $variant = 'original', ?int $minutes = null, bool $all = false): ?string
    {
        $key = $this->key($media, $variant);
        if (! $key) {
            return null;
        }

        $minutes ??= (int) config('album.url_ttl.'.($variant === 'original' ? 'original' : 'preview'));
        $expires = now()->addMinutes($minutes);

        if ($this->directUploads()) {
            return $this->disk()->temporaryUrl($key, $expires);
        }

        return URL::temporarySignedRoute('album.file', $expires, array_filter([
            'media' => $media->getKey(),
            'variant' => $variant,
            'all' => $all ? 1 : null,
        ]), absolute: false);
    }

    /**
     * The stored file for a variant; a missing thumbnail falls back to the next size up.
     */
    public function key(Media $media, string $variant): ?string
    {
        return match ($variant) {
            'thumb' => $media->thumb_key ?? $media->preview_key ?? ($media->kind === 'photo' ? $media->storage_key : null),
            'preview' => $media->preview_key ?? ($media->kind === 'photo' ? $media->storage_key : null),
            default => $media->storage_key,
        };
    }

    /**
     * One item as guests, the wall and the host see it.
     */
    public function item(Media $media, ?int $minutes = null, bool $all = false): array
    {
        return [
            'id' => $media->getKey(),
            'kind' => $media->kind,
            'thumb' => $this->url($media, 'thumb', $minutes, $all),
            'preview' => $this->url($media, 'preview', $minutes, $all),
            'original' => $this->url($media, 'original', $minutes, $all),
            'width' => $media->width,
            'height' => $media->height,
            'duration' => $media->duration,
            'name' => $media->uploader_name ?? $media->household?->display_name,
            'at' => $media->created_at?->toIso8601String(),
        ];
    }
}
