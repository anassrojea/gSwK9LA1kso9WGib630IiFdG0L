<?php

/*
| The event day (plan sections 4.1, 9 and 14.1): when the guest invitation
| shows its "today" panel, and how long door PIN links stay valid. Times are
| in the event's own time zone (App\Services\EventDay).
*/

return [

    // The "today" panel appears this many hours before the start, but never before
    // midnight of the event day itself.
    'hours_before' => (int) env('LIVE_DAY_HOURS_BEFORE', 12),

    // …and stays until this many hours after the start (weddings run past midnight).
    'hours_after' => (int) env('LIVE_DAY_HOURS_AFTER', 8),

    // Door PIN links (staff without an account).
    'door' => [
        // active links per event
        'max_passes' => (int) env('DOOR_MAX_PASSES', 10),
        // wrong PINs per minute per link and device
        'attempts_per_minute' => (int) env('DOOR_PIN_ATTEMPTS', 5),
        // wrong PINs in total before the link locks itself
        'lock_after' => (int) env('DOOR_PIN_LOCK_AFTER', 10),
        // the door app polls this often (seconds) when Reverb is unavailable
        'poll_seconds' => 15,
    ],

];
