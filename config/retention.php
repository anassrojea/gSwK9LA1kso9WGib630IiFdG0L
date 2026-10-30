<?php

/*
| Storage period expiry (plan sections 11 and 20, phase 6B): events:expire-storage runs daily.
| events.storage_until = the event's date + the plan's limits.storage_days (App\Services\Storage\StoragePeriod),
| extended by photo cloud add-ons and the admin's "Extend storage". See docs/INVOICES.md, "Storage expiry".
*/

return [

    // warning emails to the host, this many days before storage_until (each sent once per storage date)
    'warn_days' => [14, 3],

    // the add-on the warning email's "Extend storage" link preselects on the upgrade page
    'extend_addon' => env('STORAGE_EXTEND_ADDON', 'photo_cloud_90d'),

    // after storage_until the media is deleted and the event ends; this many days later it is archived (/i/* → 404)
    'archive_grace_days' => (int) env('STORAGE_ARCHIVE_GRACE_DAYS', 30),

];
