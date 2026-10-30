<?php

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use App\Services\Org\OrgSubscriptions;
use App\Services\Payments\CheckoutFailed;
use App\Services\Payments\CheckoutResult;
use App\Services\Payments\HandlesWebhooks;
use App\Services\Payments\OrderFulfillment;
use App\Services\Payments\OrderUrls;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LemonSqueezy\Laravel\LemonSqueezy;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Card payments through Lemon Squeezy (lemonsqueezy/laravel's API client).
 * Each order is a checkout of one configured one-time variant
 * (LEMON_SQUEEZY_VARIANT_ID) with a custom_price: the order total in the
 * store currency (USD), so SYP and SAR orders are charged the USD price.
 * The order code travels in checkout_data.custom and comes back in the
 * order_created webhook (POST /webhooks/lemon-squeezy, signed with
 * LEMON_SQUEEZY_SIGNING_SECRET), which marks the order paid.
 */
class LemonSqueezyGateway implements HandlesWebhooks, PaymentGateway
{
    public const ID = 'lemon_squeezy';

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
        return (bool) config('payments.lemon_squeezy.enabled')
            && filled(config('lemon-squeezy.api_key'))
            && filled(config('lemon-squeezy.store'))
            && filled(config('lemon-squeezy.signing_secret'))
            && filled(config('payments.lemon_squeezy.variant_id'));
    }

    public function currencies(): array
    {
        return array_values(array_filter(config('payments.lemon_squeezy.currencies', [])));
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array($currency, $this->currencies(), true);
    }

    public function start(Order $order, string $returnTo = 'dashboard'): CheckoutResult
    {
        $order->loadMissing('user');
        $locale = $order->user->preferredLocale();

        $payload = [
            'data' => [
                'type' => 'checkouts',
                'attributes' => [
                    // cents of the store currency
                    'custom_price' => $order->total_cents,
                    'product_options' => [
                        'name' => trans('app.payments.card.product', ['code' => $order->code, 'items' => TransferGateway::itemsLabel($order, $locale)], $locale),
                        'redirect_url' => OrderUrls::success($order, $returnTo),
                        'enabled_variants' => [(int) config('payments.lemon_squeezy.variant_id')],
                    ],
                    'checkout_data' => array_filter([
                        'email' => $order->user->email,
                        'name' => $order->user->name,
                        'custom' => ['order_code' => $order->code, 'order_id' => $order->getKey()],
                    ]),
                    'expires_at' => now()->addDay()->toIso8601String(),
                ],
                'relationships' => [
                    'store' => ['data' => ['type' => 'stores', 'id' => (string) config('lemon-squeezy.store')]],
                    'variant' => ['data' => ['type' => 'variants', 'id' => (string) config('payments.lemon_squeezy.variant_id')]],
                ],
            ],
        ];

        try {
            $response = LemonSqueezy::api('POST', 'checkouts', $payload);
        } catch (Throwable $e) {
            Log::warning('Lemon Squeezy checkout failed', ['order' => $order->code, 'error' => $e->getMessage()]);

            throw new CheckoutFailed($e->getMessage(), previous: $e);
        }

        $url = $response->json('data.attributes.url');
        if (! $url) {
            throw new CheckoutFailed('Lemon Squeezy returned no checkout URL.');
        }

        $order->update(['gateway_ref' => (string) $response->json('data.id')]);

        return CheckoutResult::redirect($url);
    }

    /**
     * POST /webhooks/lemon-squeezy: X-Signature is the HMAC-SHA256 of the raw
     * body with the signing secret. order_created (paid) marks the order paid,
     * order_refunded marks it refunded; both are safe to receive twice.
     */
    public function handleWebhook(Request $request): Response
    {
        $secret = (string) config('lemon-squeezy.signing_secret');
        $signature = (string) $request->header('X-Signature');

        if ($secret === '' || $signature === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            return new JsonResponse(['message' => 'Invalid signature.'], 403);
        }

        $payload = json_decode($request->getContent(), true) ?: [];
        $event = $payload['meta']['event_name'] ?? null;
        $attributes = $payload['data']['attributes'] ?? [];
        $code = $payload['meta']['custom_data']['order_code'] ?? null;
        $order = $code ? Order::query()->where('code', $code)->where('gateway', self::ID)->first() : null;

        // Venue & Planner Pro subscriptions (phase 8A): App\Services\Org\OrgSubscriptions
        if ($response = $this->handleSubscriptionEvent((string) $event, $payload)) {
            return $response;
        }

        if (! in_array($event, ['order_created', 'order_refunded'], true)) {
            return new JsonResponse(['message' => 'Ignored.']);
        }

        if (! $order) {
            Log::warning('Lemon Squeezy webhook for an unknown order', ['event' => $event, 'code' => $code]);

            return new JsonResponse(['message' => 'Unknown order.']);
        }

        if ($event === 'order_refunded' || ($attributes['refunded'] ?? false)) {
            $this->fulfillment->refund($order, null, 'Refunded in Lemon Squeezy');

            return new JsonResponse(['message' => 'Refunded.']);
        }

        if (($attributes['status'] ?? null) !== 'paid') {
            return new JsonResponse(['message' => 'Not paid yet.']);
        }

        $payment = $this->fulfillment->markPaid(
            $order,
            self::ID,
            (string) ($payload['data']['id'] ?? ''),
            (int) ($attributes['total'] ?? 0),
            strtoupper((string) ($attributes['currency'] ?? '')),
            $payload,
        );

        return new JsonResponse(['message' => $payment ? "Recorded: {$payment->status}." : 'Duplicate: already recorded.']);
    }

    /**
     * subscription_* events, and the first order of a subscription checkout
     * (its money is recorded from subscription_payment_success instead).
     *
     * @param  array<string, mixed>  $payload
     */
    private function handleSubscriptionEvent(string $event, array $payload): ?JsonResponse
    {
        $subscriptions = app(OrgSubscriptions::class);

        if (in_array($event, ['subscription_created', 'subscription_updated', 'subscription_cancelled', 'subscription_resumed', 'subscription_expired', 'subscription_paused', 'subscription_unpaused'], true)) {
            $subscription = $subscriptions->syncLemonSqueezy($payload);

            return new JsonResponse(['message' => $subscription ? "Org subscription: {$subscription->status}." : 'Ignored.']);
        }

        if ($event === 'subscription_payment_success') {
            [$status, $message] = $subscriptions->lemonPaymentSuccess($payload);

            return new JsonResponse(['message' => $message], $status);
        }

        if ($event === 'subscription_payment_failed') {
            return new JsonResponse(['message' => $subscriptions->lemonPaymentFailed($payload)]);
        }

        if ($event === 'order_created' && isset($payload['meta']['custom_data']['org_id']) && ! isset($payload['meta']['custom_data']['order_code'])) {
            return new JsonResponse(['message' => 'Ignored: subscription order, paid through subscription_payment_success.']);
        }

        return null;
    }
}
