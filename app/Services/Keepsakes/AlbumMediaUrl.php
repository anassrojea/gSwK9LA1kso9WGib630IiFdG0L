<?php

namespace App\Services\Keepsakes;

use App\Models\Event;
use App\Models\Media;
use App\Services\Album\Album;
use App\Services\InvitationConfig\MediaUrl;

/**
 * Media references on the thank-you card: like MediaUrl, but guests' album
 * photos resolve to their signed preview URL (App\Services\Album\Album::url)
 * instead of a plain disk URL. Signed for longer than the page's edge cache.
 */
class AlbumMediaUrl extends MediaUrl
{
    /** @var array<string, ?string> */
    private array $album = [];

    public function __construct(private Event $albumEvent)
    {
        parent::__construct($albumEvent);
    }

    public function __invoke(?string $ref): ?string
    {
        if (! $ref || ! str_starts_with($ref, 'media:')) {
            return parent::__invoke($ref);
        }

        return $this->album[$ref] ??= $this->resolve(substr($ref, 6)) ?? parent::__invoke($ref);
    }

    private function resolve(string $id): ?string
    {
        $media = Media::query()->album()->visible()->whereKey($id)->where('event_id', $this->albumEvent->getKey())->first();

        return $media ? app(Album::class)->url($media, 'preview', (int) config('album.url_ttl.wall', 720)) : null;
    }
}
