<?php

use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Payment;
use App\Notifications\OrderApproved;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Payments;

beforeEach(function () {
    Payments::setUp();
    Notification::fake();
});

function cardOrder(string $gateway = 'stripe', array $attributes = []): Order
{
    $event = Payments::event();

    return Order::factory()->card($gateway)->create($attributes + [
        'user_id' => $event->owner_id,
        'event_id' => $event->id,
        'currency' => 'USD',
        'requested_currency' => 'SYP',
        'items' => [
            ['type' => 'plan', 'id' => 'signature', 'name' => 'Signature', 'quantity' => 1, 'unit_amount' => 7900, 'amount' => 7900],
            ['type' => 'addon', 'id' => 'photo_cloud_1y', 'name' => 'Photo cloud · 1 year', 'quantity' => 1, 'unit_amount' => 2500, 'amount' => 2500],
        ],
        'total_cents' => 10400,
    ]);
}

function stripeWebhook(array $event, ?string $signature = null)
{
    $payload = json_encode($event);

    return test()->call('POST', '/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature ?? Payments::stripeSignature($payload),
    ], $payload);
}

function lemonWebhook(array $event, ?string $signature = null)
{
    $payload = json_encode($event);

    return test()->call('POST', '/webhooks/lemon-squeezy', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => $signature ?? Payments::lsSignature($payload),
    ], $payload);
}

it('marks a Stripe order paid from checkout.session.completed and grants the plan and add-ons', function () {
    $order = cardOrder();

    stripeWebhook(Payments::stripeEvent($order))->assertOk();

    $order->refresh();
    expect($order->status)->toBe('paid')
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->event->plan_id)->toBe('signature');

    $payment = Payment::query()->sole();
    expect($payment->only('provider', 'provider_ref', 'amount_cents', 'currency', 'status'))
        ->toBe(['provider' => 'stripe', 'provider_ref' => 'cs_test_'.$order->code, 'amount_cents' => 10400, 'currency' => 'USD', 'status' => 'succeeded'])
        ->and($payment->raw['type'])->toBe('checkout.session.completed');

    $cloud = Entitlement::query()->where('feature', 'photo_cloud')->sole();
    expect($cloud->source_order_id)->toBe($order->id)
        ->and($cloud->until->diffInDays($order->paid_at, true))->toBeGreaterThan(364.9)->toBeLessThan(365.1)
        ->and($order->event->storage_until->toDateString())->toBe($cloud->until->toDateString());

    Notification::assertSentTo($order->user, OrderApproved::class);
    expect(AuditLog::query()->where('action', 'order.paid')->where('entity_id', $order->id)->whereNull('actor_id')->exists())->toBeTrue();
});

it('is idempotent when Stripe retries the same event', function () {
    $order = cardOrder();
    $event = Payments::stripeEvent($order);

    stripeWebhook($event)->assertOk();
    stripeWebhook($event)->assertOk()->assertSee('duplicate');
    stripeWebhook($event)->assertOk();

    expect(Payment::query()->count())->toBe(1)
        ->and(Entitlement::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'order.paid')->count())->toBe(1);
    Notification::assertSentToTimes($order->user, OrderApproved::class, 1);
});

it('verifies the Stripe signature', function () {
    $order = cardOrder();
    $event = Payments::stripeEvent($order);
    $payload = json_encode($event);

    stripeWebhook($event, 'garbage')->assertForbidden();
    stripeWebhook($event, Payments::stripeSignature($payload, secret: 'whsec_wrong'))->assertForbidden();
    // outside the tolerance (replayed)
    stripeWebhook($event, Payments::stripeSignature($payload, time() - 3600))->assertForbidden();

    // a different body than the one signed
    $tampered = $event;
    $tampered['data']['object']['amount_total'] = 1;
    stripeWebhook($tampered, Payments::stripeSignature($payload))->assertForbidden();

    expect($order->fresh()->status)->toBe('awaiting_payment')->and(Payment::query()->count())->toBe(0);

    // without a configured secret nothing is trusted
    config(['cashier.webhook.secret' => null]);
    stripeWebhook($event)->assertForbidden();
    expect($order->fresh()->status)->toBe('awaiting_payment');
});

it('waits for unpaid sessions and records mismatched amounts without unlocking', function () {
    $order = cardOrder();

    stripeWebhook(Payments::stripeEvent($order, ['payment_status' => 'unpaid']))->assertOk()->assertSee('waiting');
    expect($order->fresh()->status)->toBe('awaiting_payment');

    // async methods confirm later
    stripeWebhook(Payments::stripeEvent($order, [], 'checkout.session.async_payment_succeeded'))->assertOk();
    expect($order->fresh()->status)->toBe('paid');

    $other = cardOrder();
    stripeWebhook(Payments::stripeEvent($other, ['amount_total' => 100, 'id' => 'cs_cheap']))->assertOk();
    expect($other->fresh()->status)->toBe('awaiting_payment')
        ->and(Payment::query()->where('provider_ref', 'cs_cheap')->value('status'))->toBe('mismatch')
        ->and(AuditLog::query()->where('action', 'order.payment_mismatch')->exists())->toBeTrue();

    // unknown orders and other event types are acknowledged, so Stripe stops retrying
    stripeWebhook(Payments::stripeEvent($other, ['metadata' => ['order_code' => 'DW-0000'], 'client_reference_id' => 'DW-0000', 'id' => 'cs_x']))->assertOk()->assertSee('unknown');
    stripeWebhook(['id' => 'evt_1', 'type' => 'charge.refunded', 'data' => ['object' => []]])->assertOk();
});

it('never trusts the success redirect', function () {
    $order = cardOrder();

    $this->actingAs($order->user)->get("/dashboard/orders/{$order->code}?checkout=success")
        ->assertInertia(fn ($page) => $page->where('order.status', 'awaiting_payment')->where('checkout', 'success')->where('order.can_retry', true));
    $this->getJson("/orders/{$order->code}/status")->assertJson(['status' => 'awaiting_payment']);

    stripeWebhook(Payments::stripeEvent($order));
    $this->getJson("/orders/{$order->code}/status")->assertJson(['status' => 'paid']);
});

it('marks a Lemon Squeezy order paid from order_created, once', function () {
    $order = cardOrder('lemon_squeezy');
    $event = Payments::lsEvent($order);

    lemonWebhook($event)->assertOk();
    lemonWebhook($event)->assertOk()->assertJson(['message' => 'Duplicate: already recorded.']);

    $order->refresh();
    expect($order->status)->toBe('paid')
        ->and($order->event->plan_id)->toBe('signature')
        ->and(Payment::query()->sole()->only('provider', 'provider_ref', 'amount_cents', 'status'))
        ->toBe(['provider' => 'lemon_squeezy', 'provider_ref' => $event['data']['id'], 'amount_cents' => 10400, 'status' => 'succeeded'])
        ->and(Entitlement::query()->count())->toBe(1);
    Notification::assertSentToTimes($order->user, OrderApproved::class, 1);
});

it('verifies the Lemon Squeezy signature', function () {
    $order = cardOrder('lemon_squeezy');
    $event = Payments::lsEvent($order);

    lemonWebhook($event, 'nope')->assertForbidden();
    lemonWebhook($event, Payments::lsSignature(json_encode($event), 'other-secret'))->assertForbidden();
    $this->postJson('/webhooks/lemon-squeezy', $event)->assertForbidden();

    config(['lemon-squeezy.signing_secret' => null]);
    lemonWebhook($event, '')->assertForbidden();

    expect($order->fresh()->status)->toBe('awaiting_payment');
});

it('ignores unpaid Lemon Squeezy orders and refunds refunded ones', function () {
    $order = cardOrder('lemon_squeezy');

    lemonWebhook(Payments::lsEvent($order, attributes: ['status' => 'pending']))->assertOk()->assertJson(['message' => 'Not paid yet.']);
    expect($order->fresh()->status)->toBe('awaiting_payment');

    lemonWebhook(Payments::lsEvent($order))->assertOk();
    lemonWebhook(Payments::lsEvent($order, 'order_refunded', ['refunded' => true]))->assertOk()->assertJson(['message' => 'Refunded.']);

    $order->refresh();
    expect($order->status)->toBe('refunded')
        ->and($order->event->plan_id)->toBeNull()
        ->and(Entitlement::query()->sole()->until->isPast() || Entitlement::query()->sole()->until->equalTo(now()))->toBeTrue();
});

it('answers 404 for gateways without webhooks', function () {
    $this->postJson('/webhooks/transfer', [])->assertNotFound();
    $this->postJson('/webhooks/paypal', [])->assertNotFound();
});
