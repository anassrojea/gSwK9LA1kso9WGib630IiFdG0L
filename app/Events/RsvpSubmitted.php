<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsGuestRow;
use App\Models\Household;
use App\Models\Rsvp;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Live RSVP updates for the host dashboard over Reverb (channel private-event.{id}).
 */
class RsvpSubmitted implements ShouldBroadcast
{
    use BroadcastsGuestRow, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Rsvp $rsvp) {}

    protected function household(): Household
    {
        return $this->rsvp->household;
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
            ...$this->dashboardPayload(),
        ];
    }
}
