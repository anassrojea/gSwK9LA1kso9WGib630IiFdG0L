<?php

namespace App\Events;

use App\Models\Rsvp;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Live RSVP updates for the host dashboard over Reverb (channel private-event.{id}).
 */
class RsvpSubmitted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Rsvp $rsvp) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('event.'.$this->rsvp->household->event_id)];
    }

    public function broadcastAs(): string
    {
        return 'rsvp.submitted';
    }

    public function broadcastWith(): array
    {
        return [
            'household_id' => $this->rsvp->household_id,
            'attending' => $this->rsvp->attending,
            'people_count' => $this->rsvp->people_count,
            'responded_at' => $this->rsvp->responded_at?->toIso8601String(),
        ];
    }
}
