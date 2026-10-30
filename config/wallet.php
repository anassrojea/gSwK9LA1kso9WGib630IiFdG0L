<?php

/*
| Wallet passes (plan section 10). A platform is offered only when it is
| configured here and the event has the "wallet" feature (Signature, or the
| wallet add-on). See docs/GUEST_EXTRAS.md for how to get the certificate and keys.
*/

return [

    'apple' => [
        // Pass Type ID certificate: a .p12 (with APPLE_PASS_CERT_PASSWORD) or a PEM holding the certificate and its key
        'cert_path' => env('APPLE_PASS_CERT_PATH'),
        'cert_password' => env('APPLE_PASS_CERT_PASSWORD', ''),
        // Apple Worldwide Developer Relations intermediate certificate (PEM)
        'wwdr_path' => env('APPLE_PASS_WWDR_PATH'),
        'pass_type_id' => env('APPLE_PASS_TYPE_ID'),
        'team_id' => env('APPLE_TEAM_ID'),
        'organization' => env('APPLE_PASS_ORGANIZATION', env('APP_NAME', 'Daawa')),
    ],

    'google' => [
        'issuer_id' => env('GOOGLE_WALLET_ISSUER_ID'),
        // the service account's JSON key file (client_email + private_key)
        'service_account_json' => env('GOOGLE_WALLET_SERVICE_ACCOUNT_JSON'),
        'issuer_name' => env('GOOGLE_WALLET_ISSUER_NAME', env('APP_NAME', 'Daawa')),
    ],

];
