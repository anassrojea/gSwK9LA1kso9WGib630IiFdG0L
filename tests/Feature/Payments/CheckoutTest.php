<?php

use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Price;
use App\Models\User;
use App\Services\Payments\PriceList;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeStripe;
use Tests\Support\Payments;

beforeEach(function () {
    Payments::setUp();
    Http::preventStrayRequests();
});

afterEach(fn () => FakeStripe::uninstall());

function checkout(array $data, ?User $user = null)
{
    return test()->actingAs($user ?? User::query()->firstOrFail())->post('/checkout', $data + ['from' => 'dashboard']);
}

it('creates a transfer order with the prices snapshotted', function () {
    $event = Payments::event();
    $user = $event->owner;

    checkout(['event_id' => $event->id, 'plan' => 'premium', 'addons' => [['id' => 'photo_cloud_90d'], ['id' => 'guests_200', 'quantity' => 2]], 'currency' => 'SAR', 'gateway' => 'transfer', 'country' => 'SA'], $user)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $order = Order::query()->sole();
    expect($order->code)->toMatch('/^DW-\d{4,6}$/')
        ->and($order->status)->toBe('awaiting_payment')
        ->and($order->method)->toBe('transfer')
        ->and($order->gateway)->toBe('transfer')
        ->and($order->currency)->toBe('SAR')
        ->and($order->requested_currency)->toBeNull()
        ->and($order->total_cents)->toBe(10900 + 3500 + 2 * 3900)
        // jsonb keeps the lines, not the key order
        ->and($order->items)->toEqual([
            ['type' => 'plan', 'id' => 'premium', 'name' => 'Premium', 'quantity' => 1, 'unit_amount' => 10900, 'amount' => 10900],
            ['type' => 'addon', 'id' => 'photo_cloud_90d', 'name' => 'Photo cloud · 90 days', 'quantity' => 1, 'unit_amount' => 3500, 'amount' => 3500],
            ['type' => 'addon', 'id' => 'guests_200', 'name' => 'Extra 200 guests', 'quantity' => 2, 'unit_amount' => 3900, 'amount' => 7800],
        ])
        ->and($order->expires_at->diffInHours(now(), true))->toBeGreaterThan(47.9)->toBeLessThan(48.1)
        ->and($user->fresh()->only('currency', 'country'))->toBe(['currency' => 'SAR', 'country' => 'SA']);

    // the snapshot does not move when prices change later
    app(PriceList::class)->set(Plan::query()->find('premium'), 'SAR', 99900);
    expect($order->fresh()->total_cents)->toBe(22200);

    expect(AuditLog::query()->where('action', 'order.created')->where('entity_id', $order->id)->exists())->toBeTrue();
});

it('sends a wizard transfer back to the review step and a dashboard one to the order page', function () {
    $event = Payments::event();

    checkout(['event_id' => $event->id, 'plan' => 'premium', 'currency' => 'SYP', 'gateway' => 'transfer', 'from' => 'wizard'], $event->owner)
        ->assertRedirect("/create/{$event->id}/review");

    $other = Payments::event();
    checkout(['event_id' => $other->id, 'plan' => 'signature', 'currency' => 'SYP', 'gateway' => 'transfer'], $other->owner)
        ->assertRedirect(route('dashboard.orders.show', Order::query()->where('event_id', $other->id)->sole()));

    expect(Order::query()->where('event_id', $other->id)->value('total_cents'))->toBe(950000);
});

it('charges Stripe in USD when the host chose SYP, and says so', function () {
    $stripe = FakeStripe::install();
    $event = Payments::event();

    $response = checkout(['event_id' => $event->id, 'plan' => 'premium', 'currency' => 'SYP', 'gateway' => 'stripe'], $event->owner);

    $order = Order::query()->sole();
    expect($order->method)->toBe('card')
        ->and($order->gateway)->toBe('stripe')
        ->and($order->currency)->toBe('USD')
        ->and($order->requested_currency)->toBe('SYP')
        ->and($order->total_cents)->toBe(2900)
        ->and($order->expires_at)->toBeNull()
        ->and($order->gateway_ref)->toBe('cs_test_'.$order->code);

    $response->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_'.$order->code);

    $session = $stripe->last('/v1/checkout/sessions')['params'];
    expect($session['mode'])->toBe('payment')
        ->and($session['line_items'][0]['price_data'])->toMatchArray(['currency' => 'usd', 'unit_amount' => 2900])
        ->and($session['client_reference_id'])->toBe($order->code)
        ->and($session['metadata']['order_code'])->toBe($order->code)
        ->and($session['success_url'])->toContain("/dashboard/orders/{$order->code}?checkout=success")
        ->and($session['cancel_url'])->toContain('checkout=cancelled')
        ->and($event->owner->fresh()->stripe_id)->toBe('cus_fake123');
});

it('charges Stripe in SAR when the host chose SAR', function () {
    FakeStripe::install();
    $event = Payments::event();

    // an Inertia visit leaves the app through a 409 + X-Inertia-Location
    $this->actingAs($event->owner)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post('/checkout', ['event_id' => $event->id, 'plan' => 'signature', 'currency' => 'SAR', 'gateway' => 'stripe', 'from' => 'wizard'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');

    $order = Order::query()->sole();
    expect([$order->currency, $order->total_cents, $order->requested_currency])->toBe(['SAR', 29900, null]);
});

it('creates a Lemon Squeezy checkout with a custom USD price and the order code', function () {
    Http::fake(['api.lemonsqueezy.com/v1/checkouts' => Http::response(['data' => ['type' => 'checkouts', 'id' => 'chk_1', 'attributes' => ['url' => 'https://daawa.lemonsqueezy.com/checkout/custom/chk_1']]], 201)]);
    $event = Payments::event();

    checkout(['event_id' => $event->id, 'plan' => 'premium', 'addons' => [['id' => 'qr_checkin']], 'currency' => 'SAR', 'gateway' => 'lemon_squeezy'], $event->owner)
        ->assertRedirect('https://daawa.lemonsqueezy.com/checkout/custom/chk_1');

    $order = Order::query()->sole();
    expect([$order->currency, $order->requested_currency, $order->total_cents, $order->gateway_ref])->toBe(['USD', 'SAR', 3900, 'chk_1']);

    Http::assertSent(function (HttpRequest $request) use ($order) {
        $data = $request->data()['data'];

        return $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer ls_test_key')
            && $data['attributes']['custom_price'] === 3900
            && $data['attributes']['checkout_data']['custom']['order_code'] === $order->code
            && $data['relationships']['store']['data']['id'] === '12345'
            && $data['relationships']['variant']['data']['id'] === '67890'
            && str_contains($data['attributes']['product_options']['redirect_url'], 'checkout=success');
    });
});

it('keeps the order and offers a retry when the card provider fails', function () {
    FakeStripe::install()->fail = true;
    $event = Payments::event();

    checkout(['event_id' => $event->id, 'plan' => 'premium', 'currency' => 'USD', 'gateway' => 'stripe'], $event->owner)
        ->assertRedirect()
        ->assertSessionHas('warning');

    expect(Order::query()->sole()->status)->toBe('awaiting_payment');
});

it('refuses what can\'t be bought', function (array $data, string $field) {
    $event = Payments::event(attributes: ['plan_id' => 'premium']);
    Entitlement::query()->create(['event_id' => $event->id, 'feature' => 'qr_checkin']);

    checkout($data + ['event_id' => $event->id, 'currency' => 'SAR', 'gateway' => 'transfer'], $event->owner)->assertSessionHasErrors($field);
    expect(Order::query()->count())->toBe(0);
})->with([
    'nothing chosen' => [[], 'plan'],
    'the plan it already has' => [['plan' => 'premium'], 'plan'],
    'a smaller plan' => [['plan' => 'free'], 'plan'],
    'a subscription plan' => [['plan' => 'venue_pro'], 'plan'],
    'an unknown add-on' => [['addons' => [['id' => 'nope']]], 'addons'],
    'an add-on inside the plan' => [['plan' => 'signature', 'addons' => [['id' => 'seating']]], 'addons'],
    'a template unlock on Premium' => [['addons' => [['id' => 'template_unlock']]], 'addons'],
    'an add-on already bought' => [['addons' => [['id' => 'qr_checkin']]], 'addons'],
    'two of a single add-on' => [['addons' => [['id' => 'wallet', 'quantity' => 2]]], 'addons'],
    'too many packs' => [['addons' => [['id' => 'guests_200', 'quantity' => 11]]], 'addons.0.quantity'],
    'an unknown currency' => [['plan' => 'signature', 'currency' => 'EUR'], 'currency'],
    'an unknown method' => [['plan' => 'signature', 'gateway' => 'bitcoin'], 'gateway'],
]);

it('refuses a method that is switched off', function () {
    config(['cashier.webhook.secret' => null]);
    $event = Payments::event();

    checkout(['event_id' => $event->id, 'plan' => 'premium', 'currency' => 'USD', 'gateway' => 'stripe'], $event->owner)->assertSessionHasErrors('gateway');
});

it('refuses a currency without a price', function () {
    Price::query()->where('priceable_id', 'premium')->where('currency', 'SYP')->delete();
    $event = Payments::event();

    checkout(['event_id' => $event->id, 'plan' => 'premium', 'currency' => 'SYP', 'gateway' => 'transfer'], $event->owner)->assertSessionHasErrors('currency');
});

it('does not let anyone pay for someone else\'s event', function () {
    $event = Payments::event();

    checkout(['event_id' => $event->id, 'plan' => 'premium', 'currency' => 'SAR', 'gateway' => 'transfer'], User::factory()->create())->assertForbidden();
    $this->post('/checkout', [])->assertRedirect();
    auth()->logout();
    $this->post('/checkout', ['event_id' => $event->id])->assertRedirect('/login');
});

it('replaces an unpaid order, but waits while a receipt is with the admin', function () {
    $event = Payments::event();
    $data = ['event_id' => $event->id, 'plan' => 'premium', 'currency' => 'SAR', 'gateway' => 'transfer'];

    checkout($data, $event->owner);
    $first = Order::query()->sole();

    checkout(['plan' => 'signature'] + $data, $event->owner)->assertSessionHasNoErrors();
    expect($first->fresh()->status)->toBe('expired')
        ->and(Order::query()->where('status', 'awaiting_payment')->sole()->planId())->toBe('signature');

    $this->post('/orders/'.Order::query()->where('status', 'awaiting_payment')->value('code').'/receipt')->assertSessionHasNoErrors();

    checkout($data, $event->owner)->assertSessionHasErrors('checkout');
    expect(Order::query()->count())->toBe(2);
});

it('gives the review step the checkout with prices in every currency', function () {
    $event = Payments::event(attributes: ['timezone' => 'Asia/Damascus']);

    $this->actingAs($event->owner)->get("/create/{$event->id}/review")
        ->assertInertia(fn ($page) => $page
            ->where('gate.allowed', false)
            ->where('checkout.country', 'SY')
            ->where('checkout.currency', 'SYP')
            ->where('checkout.required_plan', 'premium')
            ->where('checkout.plans.0.id', 'premium')
            ->where('checkout.plans.0.prices', ['SYP' => 350000, 'SAR' => 10900, 'USD' => 2900])
            ->where('checkout.gateways.0', ['id' => 'transfer', 'method' => 'transfer', 'currencies' => ['SYP', 'SAR', 'USD']])
            ->where('checkout.gateways.1.currencies', ['SAR', 'USD'])
            ->where('priceCurrency', 'SYP')
            ->where('localPrices.premium', 350000));
});
