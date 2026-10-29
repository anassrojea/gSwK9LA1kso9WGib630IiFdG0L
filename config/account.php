<?php

/*
| The "My account" area and the extra sign-in methods (plan sections 3, 15.1 and 20).
| Google sign-in is configured in config/services.php (GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET,
| GOOGLE_REDIRECT_URI); its button only shows when GOOGLE_CLIENT_ID is set.
*/

return [

    // Passwordless sign-in: a 6-digit code by email (App\Services\Account\LoginCodes)
    'login_codes' => [
        'enabled' => (bool) env('LOGIN_CODES_ENABLED', true),
        'ttl_minutes' => 10,
        'max_attempts' => 5,
        // sends per email and per IP address, per 10 minutes
        'per_email' => 3,
        'per_ip' => 10,
    ],

    // "Delete my account": the request waits this many days, then account:purge-deleted runs it
    'deletion_grace_days' => (int) env('ACCOUNT_DELETION_GRACE_DAYS', 14),

];
