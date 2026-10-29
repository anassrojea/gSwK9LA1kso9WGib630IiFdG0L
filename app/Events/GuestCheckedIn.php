<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsGuestRow;
use App\Models\Checkin;
use App\Models\Household;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A family was marked as arrived (dashboard now, the door app later).
 */
class GuestCheckedIn implements ShouldBroadcast
{
    use BroadcastsGuestRow, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Checkin $checkin) {}

    protected function household(): Household
    {
        return $this->checkin->household;
    }

    public function broadcastAs(): string
    {
        return 'guest.checked_in';
    }

    public function broadcastWith(): array
    {
        return [
            'household_id' => $this->checkin->household_id,
            'people_in' => $this->checkin->people_in,
            'scanned_at' => $this->checkin->scanned_at?->toIso8601String(),
            ...$this->dashboardPayload(),
        ];
    }
}
