<?php

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrgSubscription;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\OrgSubscriptionNotice;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeStripeBilling;
use Tests\Support\OrgFixtures;
use Tests\Support\Payments;

beforeEach(function () {
    OrgFixtures::setUp();
    Notification::fake();
    $this->stripe = FakeStripeBilling::install();
});

afterEach(fn () => FakeStripeBilling::uninstall());

function stripeOrgWebhook(array $event, ?string $signature = null)
{
    $payload = json_encode($event);

    return test()->call('POST', '/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature ?? Payments::stripeSignature($payload),
    ], $payload);
}

/**
 * A customer.subscription.* event for the org (Stripe's 2025+ shape: the period on the item).
 */
function stripeSubscriptionEvent(Organization $org, string $type = 'customer.subscription.created', array $overrides = []): array
{
    $object = array_replace_recursive([
        'id' => 'sub_org1',
        'object' => 'subscription',
        'customer' => 'cus_fake123',
        'status' => 'active',
        'cancel_at_period_end' => false,
        'metadata' => ['org_id' => $org->id, 'plan_id' => 'venue_pro', 'type' => 'org:'.$org->id, 'name' => 'org:'.$org->id],
        'items' => ['object' => 'list', 'data' => [[
            'id' => 'si_org1',
            'object' => 'subscription_item',
            'quantity' => 1,
            'current_period_start' => now()->timestamp,
            'current_period_end' => now()->addMonth()->timestamp,
            'price' => ['id' => 'price_venue_pro_usd', 'object' => 'price', 'product' => 'prod_venue', 'currency' => 'usd', 'unit_amount' => 9900, 'recurring' => ['interval' => 'month']],
        ]]],
    ], $overrides);

    return ['id' => 'evt_'.md5($type.json_encode($object)), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]];
}

function stripeInvoiceEvent(string $type = 'invoice.paid', array $overrides = []): array
{
    $object = array_replace_recursive([
        'id' => 'in_org1',
        'object' => 'invoice',
        'customer' => 'cus_fake123',
        'amount_paid' => 9900,
        'currency' => 'usd',
        'billing_reason' => 'subscription_cycle',
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_org1', 'metadata' => []]],
        'lines' => ['object' => 'list', 'data' => [['period' => ['start' => now()->timestamp, 'end' => now()->addMonth()->timestamp]]]],
    ], $overrides);

    return ['id' => 'evt_'.md5($type.json_encode($object)), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]];
}

it('opens Stripe Checkout in subscription mode on the owner, typed for the org', function () {
    [$org, $owner] = OrgFixtures::org();

    $this->actingAs($owner)->post("/org/{$org->id}/billing/subscribe", ['plan' => 'venue_pro', 'gateway' => 'stripe', 'currency' => 'USD'])
        ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_'.$org->id);

    $session = $this->stripe->last('/v1/checkout/sessions')['params'];
    expect($session['mode'])->toBe('subscription')
        ->and($session['line_items'][0]['price'])->toBe('price_venue_pro_usd')
        ->and($session['subscription_data']['metadata'])->toMatchArray(['org_id' => $org->id, 'plan_id' => 'venue_pro', 'type' => 'org:'.$org->id])
        ->and($session['client_reference_id'])->toBe($org->id)
        ->and($owner->fresh()->stripe_id)->toBe('cus_fake123')
        // nothing is active before the webhook
        ->and(OrgSubscription::query()->count())->toBe(0);
});

it('sends an inline recurring price when no Stripe price is configured', function () {
    [$org, $owner] = OrgFixtures::org();

    $this->actingAs($owner)->post("/org/{$org->id}/billing/subscribe", ['plan' => 'venue_starter_yearly', 'gateway' => 'stripe', 'currency' => 'SAR'])->assertRedirect();

    $item = $this->stripe->last('/v1/checkout/sessions')['params']['line_items'][0];
    expect($item['price_data'])->toMatchArray(['currency' => 'sar', 'unit_amount' => 189000, 'recurring' => ['interval' => 'year']]);
});

it('mirrors subscription webhooks into org_subscriptions: create, update, cancel', function () {
    [$org, $owner] = OrgFixtures::org();
    $owner->forceFill(['stripe_id' => 'cus_fake123'])->save();

    stripeOrgWebhook(stripeSubscriptionEvent($org))->assertOk();

    $subscription = OrgSubscription::query()->sole();
    expect($subscription->only('provider', 'provider_id', 'status', 'plan_id', 'currency', 'amount_cents', 'user_id'))
        ->toBe(['provider' => 'stripe', 'provider_id' => 'sub_org1', 'status' => 'active', 'plan_id' => 'venue_pro', 'currency' => 'USD', 'amount_cents' => 9900, 'user_id' => $owner->id])
        ->and($subscription->current_period_end->isSameDay(now()->addMonth()))->toBeTrue()
        ->and($org->fresh()->plan_id)->toBe('venue_pro')
        // Cashier keeps its own row on the payer, typed for the org
        ->and($owner->subscriptions()->sole()->type)->toBe('org:'.$org->id);

    // upgraded in the portal, then cancelled at period end
    stripeOrgWebhook(stripeSubscriptionEvent($org, 'customer.subscription.updated', [
        'cancel_at_period_end' => true,
        'items' => ['data' => [['price' => ['id' => 'price_venue_business_usd', 'unit_amount' => 14900]]]],
    ]))->assertOk();
    expect($subscription->fresh()->only('plan_id', 'cancel_at_period_end', 'status'))->toBe(['plan_id' => 'venue_business', 'cancel_at_period_end' => true, 'status' => 'active'])
        ->and($org->fresh()->plan_id)->toBe('venue_business');

    stripeOrgWebhook(stripeSubscriptionEvent($org, 'customer.subscription.deleted', ['status' => 'canceled', 'ended_at' => now()->timestamp]))->assertOk();
    expect($subscription->fresh()->status)->toBe('canceled')
        ->and($subscription->fresh()->ended_at)->not->toBeNull()
        ->and($org->fresh()->plan_id)->toBeNull();
});

it('marks the subscription past_due when a payment fails, and active again when an invoice is paid', function () {
    [$org, $owner] = OrgFixtures::org();
    $owner->forceFill(['stripe_id' => 'cus_fake123'])->save();
    stripeOrgWebhook(stripeSubscriptionEvent($org))->assertOk();

    stripeOrgWebhook(stripeInvoiceEvent('invoice.payment_failed', ['amount_paid' => 0]))->assertOk();
    $subscription = OrgSubscription::query()->sole();
    expect($subscription->status)->toBe('past_due')->and($subscription->past_due_since)->not->toBeNull();
    Notification::assertSentTo($owner, OrgSubscriptionNotice::class, fn ($n) => $n->kind === 'past_due');

    stripeOrgWebhook(stripeInvoiceEvent())->assertOk();
    expect($subscription->fresh()->status)->toBe('active');
});

it('records each paid period as an order with a payment and an invoice, once', function () {
    [$org, $owner] = OrgFixtures::org();
    $owner->forceFill(['stripe_id' => 'cus_fake123'])->save();
    stripeOrgWebhook(stripeSubscriptionEvent($org))->assertOk();

    stripeOrgWebhook(stripeInvoiceEvent())->assertOk();
    stripeOrgWebhook(stripeInvoiceEvent())->assertOk();

    $order = Order::query()->sole();
    expect($order->only('org_id', 'gateway', 'method', 'status', 'total_cents', 'currency', 'gateway_ref'))
        ->toBe(['org_id' => $org->id, 'gateway' => 'stripe', 'method' => 'card', 'status' => 'paid', 'total_cents' => 9900, 'currency' => 'USD', 'gateway_ref' => 'in_org1'])
        ->and(Payment::query()->sole()->provider_ref)->toBe('in_org1')
        ->and(Invoice::query()->where('order_id', $order->id)->count())->toBe(1);

    // the next month
    stripeOrgWebhook(stripeInvoiceEvent('invoice.paid', ['id' => 'in_org2']))->assertOk();
    expect(Order::query()->count())->toBe(2)->and(Invoice::query()->count())->toBe(2);
});

it('asks Stripe to retry an org invoice that arrives before its subscription', function () {
    [$org] = OrgFixtures::org();

    stripeOrgWebhook(stripeInvoiceEvent('invoice.paid', ['id' => 'in_early', 'parent' => ['subscription_details' => ['subscription' => 'sub_unknown', 'metadata' => ['org_id' => $org->id]]]]))
        ->assertStatus(409);
    // somebody else's invoice is simply ignored
    stripeOrgWebhook(stripeInvoiceEvent('invoice.paid', ['id' => 'in_other', 'parent' => ['subscription_details' => ['subscription' => 'sub_other']]]))->assertOk();
    expect(Order::query()->count())->toBe(0);
});

it('refuses unsigned or badly signed subscription webhooks', function () {
    [$org] = OrgFixtures::org();

    stripeOrgWebhook(stripeSubscriptionEvent($org), 't=1,v1=bad')->assertForbidden();
    expect(OrgSubscription::query()->count())->toBe(0);
});

it('swaps tiers with Stripe proration, cancels at period end, resumes and opens the Billing Portal', function () {
    [$org, $owner] = OrgFixtures::org();
    $owner->forceFill(['stripe_id' => 'cus_fake123'])->save();
    stripeOrgWebhook(stripeSubscriptionEvent($org))->assertOk();
    $subscription = OrgSubscription::query()->sole();
    $this->stripe->subscriptions['sub_org1'] = $this->stripe->subscription('sub_org1');

    $this->actingAs($owner)->post("/org/{$org->id}/billing/change", ['plan' => 'venue_business'])->assertSessionHasNoErrors();
    $update = collect($this->stripe->requests)->last(fn ($r) => str_ends_with($r['url'], '/v1/subscriptions/sub_org1') && $r['method'] === 'post');
    expect($update['params']['items'][0]['price'])->toBe('price_venue_business_usd')
        ->and($update['params']['proration_behavior'] ?? 'create_prorations')->toBe('create_prorations')
        ->and($subscription->fresh()->plan_id)->toBe('venue_business');

    $this->actingAs($owner)->post("/org/{$org->id}/billing/cancel")->assertSessionHasNoErrors();
    expect($subscription->fresh()->cancel_at_period_end)->toBeTrue();

    $this->actingAs($owner)->post("/org/{$org->id}/billing/resume")->assertSessionHasNoErrors();
    expect($subscription->fresh()->cancel_at_period_end)->toBeFalse();

    $this->actingAs($owner)->get("/org/{$org->id}/billing/portal")->assertRedirect('https://billing.stripe.com/p/session/test_portal');
});

it('needs configured Stripe prices to change tiers by card', function () {
    [$org, $owner] = OrgFixtures::org();
    $owner->forceFill(['stripe_id' => 'cus_fake123'])->save();
    stripeOrgWebhook(stripeSubscriptionEvent($org))->assertOk();

    $this->actingAs($owner)->post("/org/{$org->id}/billing/change", ['plan' => 'venue_starter'])->assertSessionHasErrors('plan');
    expect(OrgSubscription::query()->sole()->plan_id)->toBe('venue_pro');
});

it('replaces a live transfer subscription when a card subscription starts', function () {
    [$org, $owner] = OrgFixtures::org();
    $owner->forceFill(['stripe_id' => 'cus_fake123'])->save();
    $transfer = OrgFixtures::subscribe($org);

    stripeOrgWebhook(stripeSubscriptionEvent($org))->assertOk();

    expect($transfer->fresh()->status)->toBe('canceled')
        ->and(OrgSubscription::query()->live()->sole()->provider)->toBe('stripe');
    expect(User::query()->count())->toBeGreaterThan(0);
});
