<?php

namespace App\Services\InvitationConfig;

use App\Models\Event;
use App\Models\Media;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves a config media reference (schema $defs/mediaRef) to a URL:
 * https URLs and /storage paths pass through; media:<uuid> points at an
 * upload, which must belong to the same event and not be hidden.
 */
class MediaUrl
{
    /** @var array<string, ?string> */
    private array $resolved = [];

    public function __construct(private Event $event) {}

    public function __invoke(?string $ref): ?string
    {
        if (! $ref) {
            return null;
        }

        if (! str_starts_with($ref, 'media:')) {
            return $ref;
        }

        return $this->resolved[$ref] ??= $this->upload(substr($ref, 6));
    }

    private function upload(string $id): ?string
    {
        $media = Media::query()
            ->whereKey($id)
            ->where('event_id', $this->event->getKey())
            ->where('hidden', false)
            ->first();

        return $media ? Storage::disk(config('filesystems.media_disk', 'public'))->url($media->storage_key) : null;
    }
}
