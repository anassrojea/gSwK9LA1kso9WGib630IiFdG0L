<?php

namespace App\Events\Concerns;

use App\Models\Household;
use App\Services\Dashboard\GuestBoard;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Dashboard broadcasts on private-event.{id} (plan section 18) carry the
 * changed guest row and the event's fresh numbers, so an open dashboard
 * updates its cards and table without asking the server again. They are
 * built on the queue worker (ShouldBroadcast), never in the guest's request.
 */
trait BroadcastsGuestRow
{
    abstract protected function household(): Household;

    public function broadcastOn(): array
    {
        return [new PrivateChannel('event.'.$this->household()->event_id)];
    }

    /**
     * @return array{guest: array<string, mixed>, stats: array<string, mixed>}
     */
    protected function dashboardPayload(): array
    {
        $board = app(GuestBoard::class);
        $household = $this->household();

        return [
            'guest' => $board->row($household),
            'stats' => $board->stats($household->event),
        ];
    }
}
