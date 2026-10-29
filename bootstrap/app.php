<?php

use App\Http\Middleware\EnsureDraftAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\InviteResponseHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Guest invitations: no session, cookies or CSRF, so pages stay edge-cacheable;
            // guests are identified by the household token and every route is rate-limited.
            Route::middleware('invite')->group(base_path('routes/invite.php'));

            // Payment webhooks: signature-verified by each gateway, no session or CSRF.
            Route::middleware(SubstituteBindings::class)->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'draft' => EnsureDraftAccess::class,
        ]);

        $middleware->group('invite', [
            SubstituteBindings::class,
            InviteResponseHeaders::class,
        ]);

        // Cloudflare sits in front in production (plan section 15.2)
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
