<?php

use App\Models\Bundle;
use App\Models\BundleCredit;
use App\Models\DiscountCode;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Journey\BundleCredits;
use App\Services\Payments\CheckoutService;
use App\Services\Payments\OrderFulfillment;
use Database\Seeders\JourneySeeder;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;
use Tests\Support\Payments;

/*
| Family journey bundles (plan section 14.2): bought upfront, they grant
| event-plan credits for two years; a credit pays an event's plan as a
| zero-total order through OrderFulfillment. Plus the loyalty discount for
| returning customers and its precedence against discount codes.
*/

beforeEach(function () {
    Payments::setUp();
    $this->seed(JourneySeeder::class);
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
});

function buyBundle(User $user, string $bundle = 'engagement-henna-wedding'): Order
{
    test()->actingAs($user)->post("/bundles/{$bundle}/checkout", ['currency' => 'SAR', 'gateway' => 'transfer'])->assertRedirect();
    $order = Order::query()->where('user_id', $user->id)->whereNull('event_id')->where('status', 'awaiting_payment')->latest()->firstOrFail();

    return Journey::pay($order);
}

it('sells a bundle as an order without an event and issues its credits when approved', function () {
    $user = User::factory()->create();

    $order = buyBundle($user);

    expect($order->event_id)->toBeNull()
        ->and($order->items)->toHaveCount(1)
        ->and($order->items[0])->toMatchArray(['type' => 'bundle', 'id' => 'engagement-henna-wedding', 'amount' => 40900])
        ->and($order->total_cents)->toBe(40900);

    $credits = BundleCredit::query()->where('user_id', $user->id)->get();
    expect($credits)->toHaveCount(3)
        ->and($credits->where('plan_id', 'premium'))->toHaveCount(2)
        ->and($credits->where('plan_id', 'signature'))->toHaveCount(1)
        ->and($credits->first()->expires_at->toDateString())->toBe(now()->addDays(730)->toDateString());

    $invoice = Invoice::query()->where('order_id', $order->id)->firstOrFail();
    expect($invoice->lines[0]['name_ar'])->toBe('باقة خطوبة + حنّاء + زفاف')
        ->and($invoice->lines[0]['name_en'])->toBe('Engagement + henna + wedding bundle');
});

it('pays an event plan with a credit as a zero-total order', function () {
    $user = User::factory()->create();
    buyBundle($user);
    $credit = BundleCredit::query()->where('user_id', $user->id)->where('plan_id', 'signature')->firstOrFail();
    $event = Payments::event($user);

    $this->actingAs($user)->post('/checkout', [
        'event_id' => $event->id, 'plan' => 'signature', 'currency' => 'SAR', 'gateway' => 'transfer', 'bundle_credit' => $credit->id, 'from' => 'dashboard',
    ])->assertSessionHasNoErrors();

    $order = Order::query()->where('event_id', $event->id)->firstOrFail();
    expect($order->total_cents)->toBe(0)
        ->and($order->status)->toBe('paid')
        ->and(collect($order->items)->pluck('type')->all())->toBe(['plan', 'bundle_credit'])
        ->and($order->items[1]['amount'])->toBe(-29900)
        ->and($event->fresh()->plan_id)->toBe('signature')
        ->and($credit->fresh())->used_at->not->toBeNull()->used_event_id->toBe($event->id)->used_order_id->toBe($order->id);

    // the invoice lists the plan and the credit that paid it
    $invoice = Invoice::query()->where('order_id', $order->id)->firstOrFail();
    expect(array_column($invoice->lines, 'type'))->toBe(['plan', 'bundle_credit'])
        ->and($invoice->lines[1]['name_en'])->toBe('Bundle credit · Signature')
        ->and($invoice->total_cents)->toBe(0);

    // spent: it can't pay a second event
    $other = Payments::event($user);
    $this->actingAs($user)->post('/checkout', ['event_id' => $other->id, 'plan' => 'signature', 'currency' => 'SAR', 'gateway' => 'transfer', 'bundle_credit' => $credit->id])
        ->assertSessionHasErrors('bundle_credit');
});

it('charges add-ons on top of a plan paid with a credit, and gives the credit back on refund', function () {
    $user = User::factory()->create();
    buyBundle($user);
    $credit = BundleCredit::query()->where('user_id', $user->id)->where('plan_id', 'premium')->firstOrFail();
    $event = Payments::event($user);

    $this->actingAs($user)->post('/checkout', [
        'event_id' => $event->id, 'plan' => 'premium', 'addons' => [['id' => 'seating']], 'currency' => 'SAR', 'gateway' => 'transfer', 'bundle_credit' => $credit->id,
    ])->assertSessionHasNoErrors();
    $order = Order::query()->where('event_id', $event->id)->firstOrFail();

    expect($order->total_cents)->toBe(5500)->and($order->status)->toBe('awaiting_payment');

    // held by the unpaid order
    expect(app(BundleCredits::class)->available($user)->pluck('id'))->not->toContain($credit->id);

    Journey::pay($order);
    expect($credit->fresh()->used_at)->not->toBeNull();

    app(OrderFulfillment::class)->refund($order->fresh(), $this->admin);
    expect($credit->fresh())->used_at->toBeNull()->used_order_id->toBeNull();
});

it('refuses an expired credit, one for another plan, and someone else\'s', function () {
    $user = User::factory()->create();
    buyBundle($user);
    $premium = BundleCredit::query()->where('user_id', $user->id)->where('plan_id', 'premium')->firstOrFail();
    $event = Payments::event($user);
    $post = fn (array $data) => $this->actingAs($user)->post('/checkout', $data + ['event_id' => $event->id, 'currency' => 'SAR', 'gateway' => 'transfer']);

    $post(['plan' => 'signature', 'bundle_credit' => $premium->id])->assertSessionHasErrors(['bundle_credit' => trans('journey.bundles.errors.plan', ['plan' => trans('app.wizard.tiers.premium')])]);

    $premium->update(['expires_at' => now()->subDay()]);
    $post(['plan' => 'premium', 'bundle_credit' => $premium->id])->assertSessionHasErrors(['bundle_credit' => trans('journey.bundles.errors.expired')]);

    $stranger = User::factory()->create();
    buyBundle($stranger, 'wedding-baby');
    $theirs = BundleCredit::query()->where('user_id', $stranger->id)->where('plan_id', 'premium')->firstOrFail();
    $post(['plan' => 'premium', 'bundle_credit' => $theirs->id])->assertSessionHasErrors(['bundle_credit' => trans('journey.bundles.errors.unknown')]);

    expect(Order::query()->where('event_id', $event->id)->count())->toBe(0);
});

it('voids a refunded bundle\'s unused credits', function () {
    $user = User::factory()->create();
    $order = buyBundle($user);

    app(OrderFulfillment::class)->refund($order, $this->admin);

    expect(BundleCredit::query()->where('user_id', $user->id)->whereNull('voided_at')->count())->toBe(0)
        ->and(app(BundleCredits::class)->available($user))->toBeEmpty();
});

it('keeps an admin\'s bundle when seeding again', function () {
    Bundle::query()->find('wedding-baby')->update(['prices' => ['USD' => 7000], 'placeholder_prices' => false]);

    $this->seed(JourneySeeder::class);

    expect(Bundle::query()->find('wedding-baby')->prices)->toBe(['USD' => 7000]);
});

it('gives a returning customer the loyalty discount on their next plan, automatically', function () {
    $user = User::factory()->create();
    Journey::buy(Payments::event($user), ['plan' => 'premium']);
    $next = Payments::event($user);

    $order = Journey::buy($next, ['plan' => 'signature']);

    expect(collect($order->items)->pluck('type')->all())->toBe(['plan', 'loyalty'])
        ->and($order->items[1])->toMatchArray(['amount' => -2990, 'percent' => 10])
        ->and($order->total_cents)->toBe(29900 - 2990)
        ->and($order->discount_code)->toBeNull();

    $invoice = Invoice::query()->where('order_id', $order->id)->firstOrFail();
    expect($invoice->lines[1]['name_ar'])->toBe('خصم العميل الدائم ١٠٪')
        ->and($invoice->lines[1]['name_en'])->toBe('Returning customer 10% off');
});

it('applies the better of the loyalty discount and a code, never both', function () {
    $user = User::factory()->create();
    Journey::buy(Payments::event($user), ['plan' => 'premium']);
    DiscountCode::factory()->create(['code' => 'SMALL5', 'percent' => 5]);
    DiscountCode::factory()->create(['code' => 'BIG25', 'percent' => 25]);

    $small = Journey::buy(Payments::event($user), ['plan' => 'signature', 'discount_code' => 'SMALL5']);
    expect($small->discount_code)->toBeNull()
        ->and(collect($small->items)->pluck('type'))->toContain('loyalty')
        ->and($small->total_cents)->toBe(29900 - 2990);

    $big = Journey::buy(Payments::event($user), ['plan' => 'signature', 'discount_code' => 'BIG25']);
    expect($big->discount_code)->toBe('BIG25')
        ->and(collect($big->items)->pluck('type'))->not->toContain('loyalty')
        ->and($big->total_cents)->toBe(29900 - 7475);
});

it('shows the loyalty discount and the bundle credits at checkout', function () {
    $user = User::factory()->create();
    Journey::buy(Payments::event($user), ['plan' => 'premium']);
    buyBundle($user, 'wedding-baby');

    $options = app(CheckoutService::class)->options(Payments::event($user), $user);

    expect($options['journey']['reduction'])->toMatchArray(['type' => 'loyalty', 'percent' => 10])
        ->and($options['journey']['bundle_credits'])->toHaveCount(2)
        ->and(collect($options['journey']['bundle_credits'])->pluck('plan')->sort()->values()->all())->toBe(['premium', 'signature']);
});

it('shows the journey page with bundles, credits and the referral link', function () {
    $user = User::factory()->create();
    buyBundle($user, 'wedding-baby');

    $this->actingAs($user)->get('/account/journey')->assertOk()->assertInertia(fn ($page) => $page
        ->component('journey/Show')
        ->has('bundles', 2)
        ->has('credits', 2)
        ->where('referral.link', fn (string $link) => str_contains($link, '/r/'.$user->fresh()->referral_code)));
});
