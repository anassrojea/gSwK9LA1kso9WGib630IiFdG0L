<?php

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrgSubscription;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use LemonSqueezy\Laravel\Subscription as LemonSqueezySubscription;
use Tests\Support\OrgFixtures;
use Tests\Support\Payments;

beforeEach(function () {
    OrgFixtures::setUp();
    Notification::fake();
});

function lsOrgWebhook(array $event, ?string $signature = null)
{
    $payload = json_encode($event);

    return test()->call('POST', '/webhooks/lemon-squeezy', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SIGNATURE' => $signature ?? Payments::lsSignature($payload),
    ], $payload);
}

function lsSubscriptionEvent(Organization $org, User $owner, string $event = 'subscription_created', array $attributes = []): array
{
    return [
        'meta' => ['event_name' => $event, 'custom_data' => ['org_id' => $org->id, 'plan_id' => 'venue_pro', 'user_id' => $owner->id, 'billable_id' => $owner->id, 'subscription_type' => 'org:'.$org->id]],
        'data' => [
            'type' => 'subscriptions',
            'id' => '5501',
            'attributes' => $attributes + [
                'store_id' => 12345,
                'customer_id' => 777,
                'product_id' => 900,
                'variant_id' => 901,
                'status' => 'active',
                'cancelled' => false,
                'renews_at' => now()->addMonth()->toIso8601String(),
                'ends_at' => null,
                'trial_ends_at' => null,
                'card_brand' => 'visa',
                'card_last_four' => '4242',
            ],
        ],
    ];
}

function lsInvoiceEvent(Organization $org, string $event = 'subscription_payment_success', array $attributes = [], string $id = '8801'): array
{
    return [
        'meta' => ['event_name' => $event, 'custom_data' => ['org_id' => $org->id]],
        'data' => ['type' => 'subscription-invoices', 'id' => $id, 'attributes' => $attributes + [
            'subscription_id' => 5501, 'status' => 'paid', 'total' => 9900, 'currency' => 'USD', 'billing_reason' => 'initial',
        ]],
    ];
}

it('opens the Lemon Squeezy checkout of the plan variant with the org in the custom data', function () {
    Http::fake(['api.lemonsqueezy.com/v1/checkouts' => Http::response(['data' => ['id' => 'ck_1', 'attributes' => ['url' => 'https://daawa.lemonsqueezy.com/checkout/ck_1']]], 201)]);
    [$org, $owner] = OrgFixtures::org();

    $this->actingAs($owner)->post("/org/{$org->id}/billing/subscribe", ['plan' => 'venue_pro', 'gateway' => 'lemon_squeezy', 'currency' => 'SAR'])
        ->assertRedirect('https://daawa.lemonsqueezy.com/checkout/ck_1');

    Http::assertSent(function (Request $request) use ($org, $owner) {
        $data = $request->data()['data'];

        return $data['relationships']['variant']['data']['id'] === '901'
            && $data['attributes']['checkout_data']['custom']['org_id'] === $org->id
            && $data['attributes']['checkout_data']['custom']['subscription_type'] === 'org:'.$org->id
            && $data['attributes']['checkout_data']['custom']['billable_id'] === $owner->id;
    });
});

it('mirrors subscription webhooks into org_subscriptions and the package table', function () {
    [$org, $owner] = OrgFixtures::org();

    lsOrgWebhook(lsSubscriptionEvent($org, $owner))->assertOk();

    $subscription = OrgSubscription::query()->sole();
    expect($subscription->only('provider', 'provider_id', 'status', 'plan_id', 'user_id', 'provider_price'))
        ->toBe(['provider' => 'lemon_squeezy', 'provider_id' => '5501', 'status' => 'active', 'plan_id' => 'venue_pro', 'user_id' => $owner->id, 'provider_price' => '901'])
        ->and($org->fresh()->plan_id)->toBe('venue_pro')
        ->and(LemonSqueezySubscription::query()->sole()->only('billable_id', 'type', 'status'))->toBe(['billable_id' => $owner->id, 'type' => 'org:'.$org->id, 'status' => 'active']);

    // cancelled: on until ends_at
    lsOrgWebhook(lsSubscriptionEvent($org, $owner, 'subscription_cancelled', ['status' => 'cancelled', 'cancelled' => true, 'ends_at' => now()->addDays(20)->toIso8601String()]))->assertOk();
    expect($subscription->fresh()->only('status', 'cancel_at_period_end'))->toBe(['status' => 'active', 'cancel_at_period_end' => true])
        ->and($subscription->fresh()->current_period_end->isSameDay(now()->addDays(20)))->toBeTrue();

    lsOrgWebhook(lsSubscriptionEvent($org, $owner, 'subscription_expired', ['status' => 'expired', 'cancelled' => true, 'ends_at' => now()->toIso8601String()]))->assertOk();
    expect($subscription->fresh()->status)->toBe('canceled')->and($org->fresh()->plan_id)->toBeNull();
});

it('records each subscription payment once, with an invoice; a failed payment makes it past_due', function () {
    [$org, $owner] = OrgFixtures::org();
    lsOrgWebhook(lsSubscriptionEvent($org, $owner))->assertOk();

    lsOrgWebhook(lsInvoiceEvent($org))->assertOk();
    lsOrgWebhook(lsInvoiceEvent($org))->assertOk();
    $order = Order::query()->sole();
    expect($order->only('gateway', 'status', 'total_cents', 'currency', 'gateway_ref'))->toBe(['gateway' => 'lemon_squeezy', 'status' => 'paid', 'total_cents' => 9900, 'currency' => 'USD', 'gateway_ref' => 'lsinv_8801'])
        ->and(Invoice::query()->where('order_id', $order->id)->exists())->toBeTrue();

    lsOrgWebhook(lsInvoiceEvent($org, 'subscription_payment_failed', ['status' => 'failed', 'total' => 0], '8802'))->assertOk();
    expect(OrgSubscription::query()->sole()->status)->toBe('past_due');

    lsOrgWebhook(lsInvoiceEvent($org, 'subscription_payment_success', [], '8803'))->assertOk();
    expect(OrgSubscription::query()->sole()->status)->toBe('active')->and(Order::query()->count())->toBe(2);
});

it('ignores the first order of a subscription checkout and retries an early payment', function () {
    [$org, $owner] = OrgFixtures::org();

    // no subscription row yet: Lemon Squeezy should retry
    lsOrgWebhook(lsInvoiceEvent($org))->assertStatus(409);

    lsOrgWebhook(['meta' => ['event_name' => 'order_created', 'custom_data' => ['org_id' => $org->id]], 'data' => ['id' => '1', 'attributes' => ['status' => 'paid', 'total' => 9900]]])
        ->assertOk()->assertJsonPath('message', 'Ignored: subscription order, paid through subscription_payment_success.');
    expect(Order::query()->count())->toBe(0);
});

it('refuses badly signed subscription webhooks', function () {
    [$org, $owner] = OrgFixtures::org();

    lsOrgWebhook(lsSubscriptionEvent($org, $owner), 'bad')->assertForbidden();
    expect(OrgSubscription::query()->count())->toBe(0);
});

it('swaps the variant and cancels through the Lemon Squeezy API', function () {
    [$org, $owner] = OrgFixtures::org();
    lsOrgWebhook(lsSubscriptionEvent($org, $owner))->assertOk();
    $attributes = fn (array $extra) => ['data' => ['id' => '5501', 'attributes' => $extra + ['status' => 'active', 'product_id' => 900, 'variant_id' => 903]]];
    Http::fake([
        'api.lemonsqueezy.com/v1/subscriptions/5501' => Http::sequence()
            ->push($attributes(['variant_id' => 903]))
            ->push($attributes(['status' => 'cancelled', 'cancelled' => true, 'ends_at' => now()->addMonth()->toIso8601String()])),
    ]);

    $this->actingAs($owner)->post("/org/{$org->id}/billing/change", ['plan' => 'venue_business'])->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data()['data']['attributes']['variant_id'] === '903');
    expect(OrgSubscription::query()->sole()->plan_id)->toBe('venue_business');

    $this->actingAs($owner)->post("/org/{$org->id}/billing/cancel")->assertSessionHasNoErrors();
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE');
    expect(OrgSubscription::query()->sole()->cancel_at_period_end)->toBeTrue();
});
