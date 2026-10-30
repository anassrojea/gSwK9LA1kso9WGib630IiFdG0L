<?php

/*
| Security headers (App\Http\Middleware\SecurityHeaders) for the public site,
| the Inertia pages and the guest invitations. The Content Security Policy
| starts in report-only mode: violations are reported (to Sentry when a DSN
| is set) but nothing is blocked. Set CSP_ENFORCE=true once the reports are
| clean, see docs/DEPLOY.md "Security headers".
*/

return [

    'csp' => [
        'enabled' => (bool) env('CSP_ENABLED', true),

        // false: Content-Security-Policy-Report-Only; true: Content-Security-Policy
        'enforce' => (bool) env('CSP_ENFORCE', false),

        // extra origins for uploaded media (images, video, music) besides the media disk's own URL, comma separated
        'media_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('CSP_MEDIA_HOSTS', ''))))),

        // extra origins for fetch/XHR/WebSocket, comma separated
        'connect_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('CSP_CONNECT_HOSTS', ''))))),

        // where reports go; empty: Sentry's security endpoint derived from SENTRY_LARAVEL_DSN (or none)
        'report_uri' => env('CSP_REPORT_URI'),

        // the browser SDKs' hosts are allowed in connect-src (read from the same env keys Vite builds with)
        'sentry_dsn' => env('VITE_SENTRY_DSN', env('SENTRY_LARAVEL_DSN')),
        'posthog_host' => env('VITE_POSTHOG_KEY') ? env('VITE_POSTHOG_HOST', 'https://eu.i.posthog.com') : null,
    ],

    // Strict-Transport-Security, sent in production only (Cloudflare serves HTTPS everywhere)
    'hsts' => [
        'max_age' => (int) env('HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('HSTS_INCLUDE_SUBDOMAINS', false),
    ],

    // camera: the door app's QR scanner and photo uploads; microphone: the video guestbook
    'permissions_policy' => 'camera=(self), microphone=(self), geolocation=(self), payment=(self), fullscreen=(self), '
        .'accelerometer=(self), gyroscope=(self), magnetometer=(), usb=(), serial=(), bluetooth=(), hid=(), '
        .'midi=(), display-capture=(), browsing-topics=()',

];
