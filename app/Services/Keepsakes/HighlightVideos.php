<?php

namespace App\Services\Keepsakes;

use App\Jobs\BuildHighlightVideo;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\HighlightVideo;
use App\Models\Media;
use App\Models\MusicTrack;
use App\Models\User;
use App\Services\Entitlements;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * The highlight video (phase 8D, plan section 14.5, paid add-on): a 60–90 s
 * slideshow of the album's approved photos and short clips, vertical
 * (1080×1920) and wide (1920×1080), built in the queue (BuildHighlightVideo).
 * Files live on the media disk under events/{id}/highlight/, so the storage
 * period (events:expire-storage) deletes them with the album. The host gets a
 * signed download link by email and a preview on the album page.
 */
class HighlightVideos
{
    public const FEATURE = 'highlight_video';

    public const FORMATS = ['vertical', 'landscape'];

    public function __construct(private Entitlements $entitlements, private Ffmpeg $ffmpeg) {}

    public function unlocked(Event $event): bool
    {
        return $this->entitlements->hasFeature($event, self::FEATURE);
    }

    public function latest(Event $event): ?HighlightVideo
    {
        return HighlightVideo::query()->where('event_id', $event->getKey())->latest()->first();
    }

    /**
     * Photos guests can see, oldest first (hidden and unapproved ones never go in).
     *
     * @return Collection<int, Media>
     */
    public function photos(Event $event): Collection
    {
        return Media::query()->album()->visible()->where('event_id', $event->getKey())->where('kind', 'photo')->orderBy('created_at')->get();
    }

    /**
     * Short clips (up to a minute) guests can see.
     *
     * @return Collection<int, Media>
     */
    public function clips(Event $event): Collection
    {
        return Media::query()->album()->visible()->where('event_id', $event->getKey())->where('kind', 'video')
            ->where(fn ($q) => $q->whereNull('duration')->orWhere('duration', '<=', 60))
            ->orderBy('created_at')->limit((int) config('keepsakes.video.max_clips', 3))->get();
    }

    /**
     * @param  list<string>  $formats
     *
     * @throws ValidationException
     */
    public function request(Event $event, User $user, array $formats, ?string $musicTrackId): HighlightVideo
    {
        if (! $this->unlocked($event)) {
            throw ValidationException::withMessages(['video' => trans('keepsakes.video.errors.locked')]);
        }
        if (! $this->ffmpeg->available()) {
            throw ValidationException::withMessages(['video' => trans('keepsakes.video.errors.unavailable')]);
        }
        if ($this->photos($event)->isEmpty()) {
            throw ValidationException::withMessages(['video' => trans('keepsakes.video.errors.no_photos')]);
        }
        if (($latest = $this->latest($event)) && $latest->isBusy()) {
            throw ValidationException::withMessages(['video' => trans('keepsakes.video.errors.busy')]);
        }

        $formats = array_values(array_intersect(self::FORMATS, $formats)) ?: self::FORMATS;
        $track = $musicTrackId ? MusicTrack::query()->where('active', true)->find($musicTrackId) : null;

        $video = HighlightVideo::query()->create([
            'event_id' => $event->getKey(),
            'requested_by' => $user->getKey(),
            'music_track_id' => $track?->getKey(),
            'formats' => $formats,
            'status' => 'queued',
        ]);
        AuditLog::record('highlight_video.requested', $event, ['video' => $video->getKey(), 'formats' => $formats, 'music' => $track?->getKey()], $user);

        BuildHighlightVideo::dispatch($video->getKey());

        return $video;
    }

    public function directory(Event|string $event): string
    {
        return 'events/'.($event instanceof Event ? $event->getKey() : $event).'/highlight';
    }

    /**
     * The emailed link (works keepsakes.video.link_days days) or the album page's preview (hours).
     */
    public function url(HighlightVideo $video, string $format, ?int $minutes = null, bool $download = false): ?string
    {
        if ($video->status !== 'ready' || ! isset($video->outputs[$format])) {
            return null;
        }

        // signed as a relative path (route middleware signed:relative); the emailed link gets the app's host
        $path = URL::temporarySignedRoute(
            'highlight.file',
            now()->addMinutes($minutes ?? (int) config('album.url_ttl.preview', 360)),
            array_filter(['video' => $video->getKey(), 'format' => $format, 'download' => $download ? 1 : null]),
            absolute: false,
        );

        return $download ? url($path) : $path;
    }

    public function posterUrl(HighlightVideo $video, string $format): ?string
    {
        return $video->status === 'ready' && isset($video->outputs[$format]['poster'])
            ? URL::temporarySignedRoute('highlight.file', now()->addMinutes((int) config('album.url_ttl.preview', 360)), ['video' => $video->getKey(), 'format' => $format, 'poster' => 1], absolute: false)
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function present(?HighlightVideo $video): ?array
    {
        if (! $video) {
            return null;
        }

        return [
            'id' => $video->id,
            'status' => $video->status,
            'formats' => $video->formats,
            'error' => $video->status === 'failed' ? trans('keepsakes.video.errors.failed') : null,
            'photo_count' => $video->photo_count,
            'clip_count' => $video->clip_count,
            'finished_at' => $video->finished_at?->toIso8601String(),
            'outputs' => collect($video->outputs ?? [])->map(fn (array $o, string $format) => [
                'width' => $o['width'],
                'height' => $o['height'],
                'duration' => $o['duration'],
                'bytes' => $o['bytes'],
                'url' => $this->url($video, $format),
                'poster' => $this->posterUrl($video, $format),
                'download' => $this->url($video, $format, (int) config('keepsakes.video.link_days', 7) * 24 * 60, download: true),
            ])->all(),
        ];
    }

    /**
     * @return list<array{id: string, title: string, artist: ?string}>
     */
    public function tracks(): array
    {
        return MusicTrack::query()->where('active', true)->orderBy('sort')->orderBy('title')->get()
            ->map(fn (MusicTrack $t) => ['id' => $t->id, 'title' => $t->title, 'artist' => $t->artist])
            ->values()->all();
    }
}
