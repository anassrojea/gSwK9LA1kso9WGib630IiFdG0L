<?php

namespace App\Events;

use App\Models\Media;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A photo is ready for the live wall (plan section 18: public-wall.{slug}).
 * Sent when a visible upload has its thumbnails, and when the host approves
 * one. The channel is public, so the message carries no URL: the wall asks
 * its feed (which checks the wall's secret) for the new photo.
 */
class MediaUploaded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Media $media) {}

    public function broadcastOn(): Channel
    {
        return new Channel('public-wall.'.$this->media->event->invitation?->slug);
    }

    public function broadcastAs(): string
    {
        return 'media.uploaded';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->media->getKey(),
            'kind' => $this->media->kind,
            'at' => $this->media->created_at?->toIso8601String(),
        ];
    }

    public function broadcastWhen(): bool
    {
        return $this->media->event?->invitation?->slug !== null && ! $this->media->hidden;
    }
}
