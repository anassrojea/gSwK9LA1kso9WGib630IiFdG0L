<?php

use App\Models\AuditLog;
use App\Models\DiscountCode;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Services\Payments\OrderFulfillment;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeStripe;
use Tests\Support\Payments;

beforeEach(function () {
    Payments::setUp();
    Http::preventStrayRequests();
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
});

afterEach(fn () => FakeStripe::uninstall());

/**
 * POST /checkout for a fresh event (premium, SAR transfer unless overridden) with a discount code.
 */
function checkoutWithCode(?string $code, array $data = [], ?Event $event = null)
{
    $event ??= Payments::event();
    $response = test()->actingAs($event->owner)->post('/checkout', $data + [
        'event_id' => $event->id,
        'plan' => 'premium',
        'currency' => 'SAR',
        'gateway' => 'transfer',
        'discount_code' => $code,
        'from' => 'dashboard',
    ]);

    return [$response, $event, Order::query()->where('event_id', $event->id)->latest()->first()];
}

it('takes a percentage off, ignoring case, and snapshots the code on the order', function () {
    $code = DiscountCode::factory()->create(['code' => 'Eid20', 'percent' => 20]);
    expect($code->code)->toBe('EID20');

    [$response, , $order] = checkoutWithCode('  eid20 ', ['addons' => [['id' => 'photo_cloud_90d']]]);

    $response->assertSessionHasNoErrors();
    expect($order->discount_code_id)->toBe($code->id)
        ->and($order->discount_code)->toBe('EID20')
        ->and($order->discount_cents)->toBe(2880)
        ->and($order->total_cents)->toBe(10900 + 3500 - 2880)
        ->and($order->subtotal())->toBe(14400)
        // the lines keep their list prices
        ->and(array_sum(array_column($order->items, 'amount')))->toBe(14400)
        ->and(AuditLog::query()->where('action', 'order.created')->value('data'))->toMatchArray(['discount_code' => 'EID20', 'discount' => 2880]);
});

it('applies only to the lines the code covers', function () {
    DiscountCode::factory()->create(['code' => 'PLANS10', 'percent' => 10, 'applies_to_addons' => false]);
    DiscountCode::factory()->create(['code' => 'SIGONLY', 'percent' => 10, 'plan_ids' => ['signature']]);
    DiscountCode::factory()->create(['code' => 'ADDONS', 'percent' => 50, 'applies_to_plans' => false]);

    [, , $order] = checkoutWithCode('PLANS10', ['addons' => [['id' => 'photo_cloud_90d']]]);
    expect($order->discount_cents)->toBe(1090);

    [$response, $event] = checkoutWithCode('SIGONLY');
    $response->assertSessionHasErrors(['discount_code' => trans('app.checkout.errors.discount_items')]);
    expect(Order::query()->where('event_id', $event->id)->exists())->toBeFalse();

    [$response] = checkoutWithCode('ADDONS');
    $response->assertSessionHasErrors('discount_code');

    [, , $order] = checkoutWithCode('ADDONS', ['addons' => [['id' => 'guests_200', 'quantity' => 2]]]);
    expect($order->discount_cents)->toBe(3900)->and($order->total_cents)->toBe(10900 + 7800 - 3900);
});

it('takes a fixed amount per currency, and only in a currency it has an amount for', function () {
    DiscountCode::factory()->fixed(['SAR' => 2000, 'SYP' => 50000])->create(['code' => 'FIXED']);

    [, , $order] = checkoutWithCode('FIXED');
    expect([$order->currency, $order->discount_cents, $order->total_cents])->toBe(['SAR', 2000, 8900]);

    [, , $order] = checkoutWithCode('FIXED', ['currency' => 'SYP']);
    expect([$order->currency, $order->discount_cents])->toBe(['SYP', 50000]);

    // Stripe can't charge SYP: the order is charged in USD, where this code has no amount
    FakeStripe::install();
    [$response, $event] = checkoutWithCode('FIXED', ['currency' => 'SYP', 'gateway' => 'stripe']);
    $response->assertSessionHasErrors(['discount_code' => trans('app.checkout.errors.discount_currency', ['currency' => trans('app.checkout.currencies.USD')])]);
    expect(Order::query()->where('event_id', $event->id)->exists())->toBeFalse();
});

it('charges Stripe the discounted total, in USD after the fallback', function () {
    $stripe = FakeStripe::install();
    DiscountCode::factory()->fixed(['SAR' => 2000, 'USD' => 500])->create(['code' => 'BOTH']);

    [$response, , $order] = checkoutWithCode('BOTH', ['currency' => 'SYP', 'gateway' => 'stripe']);

    $response->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_'.$order->code);
    expect([$order->currency, $order->requested_currency, $order->discount_cents, $order->total_cents])->toBe(['USD', 'SYP', 500, 2400])
        ->and($stripe->last('/v1/checkout/sessions')['params']['line_items'][0]['price_data'])->toMatchArray(['currency' => 'usd', 'unit_amount' => 2400]);

    // the webhook for the discounted amount unlocks the plan
    $payload = json_encode(Payments::stripeEvent($order));
    $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => Payments::stripeSignature($payload), 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();
    expect($order->fresh()->status)->toBe('paid')->and($order->event->fresh()->plan_id)->toBe('premium');
});

it('charges Lemon Squeezy the discounted total', function () {
    Http::fake(['api.lemonsqueezy.com/v1/checkouts' => Http::response(['data' => ['type' => 'checkouts', 'id' => 'chk_9', 'attributes' => ['url' => 'https://daawa.lemonsqueezy.com/checkout/custom/chk_9']]], 201)]);
    DiscountCode::factory()->create(['code' => 'HALF', 'percent' => 50]);

    [, , $order] = checkoutWithCode('HALF', ['gateway' => 'lemon_squeezy']);

    expect([$order->currency, $order->discount_cents, $order->total_cents])->toBe(['USD', 1450, 1450]);
    Http::assertSent(fn (HttpRequest $request) => $request->data()['data']['attributes']['custom_price'] === 1450);
});

it('never makes a total negative, and a free order is unlocked at once without a gateway', function (array $code) {
    $stripe = FakeStripe::install();
    DiscountCode::factory()->create(['code' => 'GIFT'] + $code);

    [$response, $event, $order] = checkoutWithCode('GIFT', ['gateway' => 'stripe']);

    $response->assertRedirect(route('dashboard.orders.show', $order))->assertSessionHas('status');
    expect($order->total_cents)->toBe(0)
        ->and($order->discount_cents)->toBe(10900)
        ->and($order->status)->toBe('paid')
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->payments()->sole()->only('provider', 'amount_cents', 'status'))->toBe(['provider' => 'discount', 'amount_cents' => 0, 'status' => 'succeeded'])
        ->and($event->fresh()->plan_id)->toBe('premium')
        ->and($stripe->requests)->toBe([])
        ->and(AuditLog::query()->where('action', 'discount.redeemed')->exists())->toBeTrue();
})->with([
    '100%' => [['percent' => 100]],
    'a fixed amount above the total' => [['percent' => null, 'amounts' => ['SAR' => 999999]]],
]);

it('refuses expired, future, switched-off and unknown codes', function () {
    DiscountCode::factory()->create(['code' => 'OLD', 'ends_at' => now()->subMinute()]);
    DiscountCode::factory()->create(['code' => 'SOON', 'starts_at' => now()->addDay()]);
    DiscountCode::factory()->create(['code' => 'OFF', 'active' => false]);
    DiscountCode::factory()->create(['code' => 'NOW', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);

    checkoutWithCode('OLD')[0]->assertSessionHasErrors(['discount_code' => trans('app.checkout.errors.discount_expired')]);
    checkoutWithCode('SOON')[0]->assertSessionHasErrors(['discount_code' => trans('app.checkout.errors.discount_not_started')]);
    checkoutWithCode('OFF')[0]->assertSessionHasErrors(['discount_code' => trans('app.checkout.errors.discount_expired')]);
    checkoutWithCode('NOPE')[0]->assertSessionHasErrors(['discount_code' => trans('app.checkout.errors.discount_unknown')]);
    checkoutWithCode('NOW')[0]->assertSessionHasNoErrors();

    // and once it expires, the order placed before still keeps its price
    $this->travel(2)->days();
    expect(Order::query()->sole()->discount_cents)->toBe(2180);
});

it('counts uses only when paid or approved, and gives them back on expiry, rejection and refund', function () {
    $code = DiscountCode::factory()->create(['code' => 'ONCE', 'max_uses' => 1]);
    $fulfillment = app(OrderFulfillment::class);

    // two customers can hold the code while neither has paid
    [, , $first] = checkoutWithCode('ONCE');
    [, , $second] = checkoutWithCode('ONCE');
    expect($first->discount_code_id)->toBe($code->id)->and($second->discount_code_id)->toBe($code->id);

    // one expires, one is rejected: still unused
    $this->travel(49)->hours();
    expect($fulfillment->expire($first))->toBeTrue();
    $fulfillment->rejectTransfer($second, $this->admin, 'No transfer found');
    $this->travelBack();

    [, , $third] = checkoutWithCode('ONCE');
    $fulfillment->approveTransfer($third, $this->admin, 'REF-1');

    // now it is used up
    [$response, $event] = checkoutWithCode('ONCE');
    $response->assertSessionHasErrors(['discount_code' => trans('app.checkout.errors.discount_used_up')]);
    expect(Order::query()->where('event_id', $event->id)->exists())->toBeFalse();

    // a refund gives the use back
    $fulfillment->refund($third->fresh(), $this->admin, 'Cancelled');
    checkoutWithCode('ONCE', event: $event)[0]->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('entity', 'DiscountCode')->orderBy('id')->pluck('action')->all())->toBe(['discount.redeemed', 'discount.released']);
});

it('limits uses per customer', function () {
    DiscountCode::factory()->create(['code' => 'MINE', 'max_uses_per_user' => 1]);
    $fulfillment = app(OrderFulfillment::class);

    [, $event, $order] = checkoutWithCode('MINE');
    $fulfillment->approveTransfer($order, $this->admin, 'REF-2');

    // the same customer, another event
    $again = Payments::event($event->owner);
    checkoutWithCode('MINE', event: $again)[0]->assertSessionHasErrors(['discount_code' => trans('app.checkout.errors.discount_used_by_you')]);

    // someone else can still use it
    checkoutWithCode('MINE')[0]->assertSessionHasNoErrors();
});

it('still unlocks a paid order when the code ran out meanwhile, and flags it', function () {
    DiscountCode::factory()->create(['code' => 'RACE', 'max_uses' => 1]);
    $fulfillment = app(OrderFulfillment::class);

    [, , $first] = checkoutWithCode('RACE');
    [, , $second] = checkoutWithCode('RACE');
    $fulfillment->approveTransfer($first, $this->admin, 'R-1');
    $fulfillment->approveTransfer($second, $this->admin, 'R-2');

    expect($second->fresh()->status)->toBe('approved')
        ->and(AuditLog::query()->where('action', 'discount.over_limit')->count())->toBe(1);
});

it('checks a code for the checkout UI without creating anything', function () {
    $event = Payments::event();
    DiscountCode::factory()->fixed(['SAR' => 2000])->create(['code' => 'SHOW', 'applies_to_addons' => false]);

    $this->actingAs($event->owner)
        ->postJson('/checkout/discount', ['event_id' => $event->id, 'code' => 'show'])
        ->assertOk()
        ->assertExactJson(['discount' => ['code' => 'SHOW', 'percent' => null, 'amounts' => ['SAR' => 2000], 'plans' => true, 'addons' => false]]);

    $this->actingAs($event->owner)
        ->postJson('/checkout/discount', ['event_id' => $event->id, 'code' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['discount_code' => trans('app.checkout.errors.discount_unknown')]);

    // only for your own events
    $this->actingAs(User::factory()->create())
        ->postJson('/checkout/discount', ['event_id' => $event->id, 'code' => 'SHOW'])
        ->assertForbidden();

    expect(Order::query()->count())->toBe(0);
});

it('shows the discount on the order page', function () {
    DiscountCode::factory()->create(['code' => 'EID20', 'percent' => 20]);
    [, $event, $order] = checkoutWithCode('EID20');

    $this->actingAs($event->owner)->get(route('dashboard.orders.show', $order))
        ->assertInertia(fn ($page) => $page->where('order.discount.code', 'EID20')->where('order.discount.amount', 2180)->where('order.total', 8720));
});
