<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Payments\HandlesWebhooks;
use App\Services\Payments\PaymentGateways;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /webhooks/{gateway}: hands the raw request to the gateway (e.g.
 * /webhooks/lemon-squeezy → LemonSqueezyGateway), which verifies its own
 * signature. Stripe has its own route through Cashier.
 */
class GatewayWebhookController extends Controller
{
    public function __invoke(Request $request, string $gateway, PaymentGateways $gateways): Response
    {
        $handler = $gateways->forWebhook($gateway);
        abort_unless($handler instanceof HandlesWebhooks, 404);

        return $handler->handleWebhook($request);
    }
}
