<?php

namespace App\Http\Controllers\Dashboard\Concerns;

use App\Models\Event;
use App\Services\Digits;
use App\Services\Entitlements;
use Illuminate\Validation\ValidationException;

/**
 * The plan's soft guest limit, checked on every write (plan section 16),
 * with the same message as the wizard.
 */
trait ChecksGuestLimit
{
    protected function ensureRoom(Entitlements $entitlements, Event $event, int $adding, string $field): void
    {
        if ($adding <= 0 || $entitlements->canAddGuests($event, $adding)) {
            return;
        }

        $limit = (int) $entitlements->limit($event, 'guests');
        $left = max(0, $limit - (int) $event->households()->sum('seats_allowed'));

        throw ValidationException::withMessages([
            $field => trans('app.wizard.guests.limit', ['limit' => Digits::localize($limit), 'left' => Digits::localize($left)]),
        ]);
    }
}
