<?php

/*
| Link preview images (/i/{token}/og.png, plan sections 14.1 and 15.1): a small
| Blade view screenshotted by headless Chromium, so Arabic is shaped by a real
| browser. See App\Services\OgImage\OgImage and docs/DEPLOY.md.
*/

return [

    // Chromium / Chrome / chrome-headless-shell binary. Empty: look in the usual places
    // (App\Services\OgImage\Chromium::CANDIDATES).
    'chrome_path' => env('OG_CHROME_PATH'),

    // Chromium refuses to run as root without this; also needed where unprivileged user
    // namespaces are blocked (e.g. Ubuntu 24.04's AppArmor default). Safe here: the page
    // is our own HTML with every network request blocked.
    'chrome_no_sandbox' => (bool) env('OG_CHROME_NO_SANDBOX', false),

    // Seconds one render may take in the queue (GenerateOgImage).
    'timeout' => (int) env('OG_TIMEOUT', 30),

    // Seconds GET /i/{token}/og.png waits for a missing image before redirecting to the fallback.
    'sync_timeout' => (int) env('OG_SYNC_TIMEOUT', 8),

    // Generate images ahead of time (household created / renamed, invitation published / edited).
    'warm' => (bool) env('OG_WARM', true),

    // Seconds to wait after an edit to a published invitation, so a burst of autosaves renders once.
    'edit_delay' => (int) env('OG_EDIT_DELAY', 30),

    // Download the theme's Google Fonts once (stored on the local disk) and inline them into
    // the page, so Chromium never touches the network. Off: system fonts only.
    'embed_fonts' => (bool) env('OG_EMBED_FONTS', true),

    // Shown when an image cannot be rendered in time (public/og-fallback.png).
    'fallback' => 'og-fallback.png',

];
