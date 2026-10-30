<?php

namespace App\Http\Controllers\Webhooks;

use App\Services\Org\OrgSubscriptions;
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
 *
 * Venue & Planner Pro (phase 8A): Cashier keeps its own subscriptions rows
 * (type "org:{org_id}" on the payer user), then each subscription event is
 * mirrored into org_subscriptions, and invoice.paid / invoice.payment_failed
 * record each period's payment or the past_due state (App\Services\Org\OrgSubscriptions).
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

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionCreated(array $payload)
    {
        parent::handleCustomerSubscriptionCreated($payload);

        return $this->syncOrgSubscription($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionUpdated(array $payload)
    {
        parent::handleCustomerSubscriptionUpdated($payload);

        return $this->syncOrgSubscription($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionDeleted(array $payload)
    {
        parent::handleCustomerSubscriptionDeleted($payload);

        return $this->syncOrgSubscription($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleInvoicePaid(array $payload): Response
    {
        [$status, $message] = app(OrgSubscriptions::class)->stripeInvoicePaid($payload['data']['object'] ?? [], $payload);

        return new Response($message, $status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleInvoicePaymentFailed(array $payload): Response
    {
        return $this->successMethod(['message' => app(OrgSubscriptions::class)->stripeInvoiceFailed($payload['data']['object'] ?? [])]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncOrgSubscription(array $payload): Response
    {
        $subscription = app(OrgSubscriptions::class)->syncStripe($payload['data']['object'] ?? []);

        return $this->successMethod(['message' => $subscription ? "org subscription: {$subscription->status}" : 'Webhook Handled']);
    }

    protected function successMethod($parameters = []): Response
    {
        return new Response($parameters['message'] ?? 'Webhook Handled', 200);
    }
}
