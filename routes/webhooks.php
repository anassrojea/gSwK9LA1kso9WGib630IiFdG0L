<?php

use App\Http\Controllers\Webhooks\GatewayWebhookController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Payment webhooks (plan section 18): no session, cookies or CSRF; every call
| is signature-verified by its gateway. Cloudflare must not challenge or cache
| /webhooks/* (docs/DEPLOY.md, "Payments").
*/

Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handleWebhook'])
    ->middleware('throttle:webhooks')
    ->name('webhooks.stripe');

Route::post('/webhooks/{gateway}', GatewayWebhookController::class)
    ->where('gateway', '[a-z0-9-]{2,32}')
    ->middleware('throttle:webhooks')
    ->name('webhooks.gateway');
