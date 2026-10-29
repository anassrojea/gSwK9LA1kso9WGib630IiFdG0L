<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsGuestRow;
use App\Models\Household;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A guest opened their personal link (recorded by OpenTracker).
 */
class HouseholdOpened implements ShouldBroadcast
{
    use BroadcastsGuestRow, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Household $household) {}

    protected function household(): Household
    {
        return $this->household;
    }

    public function broadcastAs(): string
    {
        return 'household.opened';
    }

    public function broadcastWith(): array
    {
        return [
            'household_id' => $this->household->getKey(),
            ...$this->dashboardPayload(),
        ];
    }
}
