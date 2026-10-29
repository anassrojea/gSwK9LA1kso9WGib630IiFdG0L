<?php

namespace App\Http\Controllers\Webhooks;

use App\Services\Payments\Gateways\StripeGateway;
use Illuminate\Http\Request;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /webhooks/stripe. Cashier's controller verifies the Stripe-Signature
 * header (STRIPE_WEBHOOK_SECRET, through its VerifyWebhookSignature
 * middleware) and dispatches by event type; we add the one-time Checkout
 * events. Without a secret the endpoint refuses everything rather than
 * trusting unsigned calls.
 */
class StripeWebhookController extends CashierWebhookController
{
    public function handleWebhook(Request $request)
    {
        abort_if(blank(config('cashier.webhook.secret')), 403, 'Stripe webhooks are not configured.');

        return parent::handleWebhook($request);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCheckoutSessionCompleted(array $payload): Response
    {
        return $this->successMethod(['message' => app(StripeGateway::class)->completeSession($payload['data']['object'] ?? [], $payload)]);
    }

    /**
     * Delayed payment methods confirm here instead of in checkout.session.completed.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCheckoutSessionAsyncPaymentSucceeded(array $payload): Response
    {
        return $this->handleCheckoutSessionCompleted($payload);
    }

    protected function successMethod($parameters = []): Response
    {
        return new Response($parameters['message'] ?? 'Webhook Handled', 200);
    }
}
