<?php

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use App\Services\Payments\CheckoutFailed;
use App\Services\Payments\CheckoutResult;
use App\Services\Payments\OrderFulfillment;
use App\Services\Payments\OrderUrls;
use App\Services\Payments\PaymentGateway;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Card payments through Laravel Cashier's one-time Stripe Checkout (no
 * subscriptions yet: Venue Pro comes later). Stripe can't serve Syria, so it
 * takes SAR and USD only and SYP orders are charged the USD price.
 * Enabled when STRIPE_KEY, STRIPE_SECRET and STRIPE_WEBHOOK_SECRET are set.
 * The webhook (App\Http\Controllers\Webhooks\StripeWebhookController) marks
 * orders paid; the success redirect never does.
 */
class StripeGateway implements PaymentGateway
{
    public const ID = 'stripe';

    public function __construct(private OrderFulfillment $fulfillment) {}

    public function id(): string
    {
        return self::ID;
    }

    public function method(): string
    {
        return 'card';
    }

    public function enabled(): bool
    {
        return (bool) config('payments.stripe.enabled')
            && filled(config('cashier.key'))
            && filled(config('cashier.secret'))
            && filled(config('cashier.webhook.secret'));
    }

    public function currencies(): array
    {
        return array_values(config('payments.stripe.currencies', []));
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array($currency, $this->currencies(), true);
    }

    public function start(Order $order, string $returnTo = 'dashboard'): CheckoutResult
    {
        $order->loadMissing('user');
        $metadata = ['order_code' => $order->code, 'order_id' => $order->getKey()];
        $locale = $order->user->preferredLocale();

        try {
            // SAR and USD have two decimals both here and at Stripe, so total_cents is Stripe's unit_amount
            $checkout = $order->user->checkout([[
                'price_data' => [
                    'currency' => strtolower($order->currency),
                    'product_data' => ['name' => trans('app.payments.card.product', ['code' => $order->code, 'items' => TransferGateway::itemsLabel($order, $locale)], $locale)],
                    'unit_amount' => $order->total_cents,
                ],
                'quantity' => 1,
            ]], [
                'success_url' => OrderUrls::success($order, $returnTo),
                'cancel_url' => OrderUrls::cancel($order, $returnTo),
                'client_reference_id' => $order->code,
                'metadata' => $metadata,
                'payment_intent_data' => ['metadata' => $metadata],
                'locale' => 'auto',
            ]);
        } catch (ApiErrorException $e) {
            Log::warning('Stripe checkout failed', ['order' => $order->code, 'error' => $e->getMessage()]);

            throw new CheckoutFailed($e->getMessage(), previous: $e);
        }

        $session = $checkout->asStripeCheckoutSession();
        $order->update(['gateway_ref' => $session->id]);

        return CheckoutResult::redirect((string) $session->url);
    }

    /**
     * checkout.session.completed / async_payment_succeeded: a paid one-time
     * session marks its order paid. Returns what happened, for the webhook response.
     *
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>  $payload
     */
    public function completeSession(array $session, array $payload): string
    {
        if (($session['mode'] ?? 'payment') !== 'payment') {
            return 'ignored: not a one-time checkout';
        }

        // bank debits and the like confirm later, in checkout.session.async_payment_succeeded
        if (($session['payment_status'] ?? null) !== 'paid') {
            return 'waiting: not paid yet';
        }

        $code = $session['metadata']['order_code'] ?? $session['client_reference_id'] ?? null;
        $order = $code ? Order::query()->where('code', $code)->where('gateway', self::ID)->first() : null;

        if (! $order) {
            Log::warning('Stripe webhook for an unknown order', ['session' => $session['id'] ?? null, 'code' => $code]);

            return 'ignored: unknown order';
        }

        $payment = $this->fulfillment->markPaid(
            $order,
            self::ID,
            (string) $session['id'],
            (int) ($session['amount_total'] ?? 0),
            strtoupper((string) ($session['currency'] ?? '')),
            $payload,
        );

        return $payment ? "recorded: {$payment->status}" : 'duplicate: already recorded';
    }
}
