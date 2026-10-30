<?php

use App\Models\AccountCredit;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Referral;
use App\Models\User;
use App\Services\Journey\Credits;
use App\Services\Journey\Referrals;
use App\Services\Payments\OrderFulfillment;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Journey;
use Tests\Support\Payments;

/*
| Referrals (plan section 14.7): /r/{code} and the opted-in "صمّم دعوتك"
| footer set a 30-day cookie; sign-up records the referral; the referred
| user's first paid order credits the referrer once (monthly cap, no
| self-referral); credit is spendable at checkout.
*/

beforeEach(function () {
    Payments::setUp();
    Notification::fake();
});

function signUpWith(?string $code, string $email = 'friend@example.com', string $ip = '10.0.0.9'): User
{
    $request = test()->withServerVariables(['REMOTE_ADDR' => $ip]);
    if ($code) {
        $request = $request->withCookie('daawa_ref', $code);
    }
    $request->post('/register', ['name' => 'Friend', 'email' => $email, 'password' => 'Secret-pass-1234', 'password_confirmation' => 'Secret-pass-1234'])
        ->assertRedirect();
    auth()->logout();

    return User::query()->where('email', $email)->firstOrFail();
}

it('gives every user a referral link that sets a 30-day cookie', function () {
    $referrer = User::factory()->create();
    $link = app(Referrals::class)->link($referrer);

    expect($link)->toEndWith('/r/'.$referrer->fresh()->referral_code)
        ->and($referrer->fresh()->referral_code)->toMatch('/^[A-HJ-NP-Z2-9]{8}$/');

    $this->get('/r/'.$referrer->referral_code)
        ->assertRedirect()
        ->assertCookie('daawa_ref', $referrer->referral_code);

    $cookie = collect($this->get('/r/'.$referrer->referral_code)->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'daawa_ref');
    expect($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(29)->timestamp)->toBeLessThanOrEqual(now()->addDays(30)->timestamp + 5);

    $this->get('/r/ABCDEFGH')->assertRedirect()->assertCookieMissing('daawa_ref');
});

it('attributes the design-yours footer click to the opted-in event owner', function () {
    $owner = User::factory()->create(['referral_footer' => true]);
    $event = Event::factory()->create(['owner_id' => $owner->id]);
    $invitation = Invitation::factory()->for($event)->create();

    $this->get('/r/design-yours?e='.$invitation->slug, ['User-Agent' => 'Mozilla/5.0'])
        ->assertRedirect()
        ->assertCookie('daawa_ref', $owner->fresh()->referral_code);

    $owner->forceFill(['referral_footer' => false])->save();
    $this->get('/r/design-yours?e='.$invitation->slug, ['User-Agent' => 'Mozilla/5.0'])->assertCookieMissing('daawa_ref');
});

it('records the referral on sign-up and credits the referrer on the first paid order only', function () {
    $referrer = User::factory()->create(['email' => 'host@daawa-family.com']);
    $code = app(Referrals::class)->codeFor($referrer);

    $friend = signUpWith($code);
    $referral = Referral::query()->where('referred_user_id', $friend->id)->firstOrFail();
    expect($referral)->referrer_user_id->toBe($referrer->id)->reward_status->toBe('pending')->code->toBe($code);

    $friend->forceFill(['email_verified_at' => now()])->save();
    $first = Journey::buy(Payments::event($friend), ['plan' => 'premium']);

    // the welcome discount on the referred user's first order
    expect(collect($first->items)->pluck('type')->all())->toBe(['plan', 'referral_welcome'])
        ->and($first->total_cents)->toBe(10900 - 1090);

    expect($referral->fresh())->reward_status->toBe('rewarded')->reward_order_id->toBe($first->id)
        ->and(app(Credits::class)->balance($referrer, 'SAR'))->toBe(1900);

    // a second paid order earns nothing more
    Journey::buy(Payments::event($friend), ['plan' => 'premium']);
    expect(AccountCredit::query()->where('user_id', $referrer->id)->count())->toBe(1);
});

it('blocks a self-referral (same email or same IP and email domain)', function () {
    $referrer = User::factory()->create(['email' => 'rana.ali@gmail.com', 'signup_ip' => '10.1.1.1']);
    $code = app(Referrals::class)->codeFor($referrer);

    $sameMailbox = signUpWith($code, 'ranaali+2@gmail.com', '10.9.9.9');
    expect(Referral::query()->where('referred_user_id', $sameMailbox->id)->value('reward_status'))->toBe('blocked');

    $sameHouse = signUpWith($code, 'other@gmail.com', '10.1.1.1');
    expect(Referral::query()->where('referred_user_id', $sameHouse->id)->first())->reward_status->toBe('blocked')->reason->toBe('same_ip_and_domain');

    $friend = signUpWith($code, 'friend@outlook.com', '10.1.1.1');
    expect(Referral::query()->where('referred_user_id', $friend->id)->value('reward_status'))->toBe('pending');

    // a blocked sign-up gets no welcome discount and earns nothing
    $sameHouse->forceFill(['email_verified_at' => now()])->save();
    $order = Journey::buy(Payments::event($sameHouse), ['plan' => 'premium']);
    expect($order->total_cents)->toBe(10900)->and(app(Credits::class)->balance($referrer, 'SAR'))->toBe(0);
});

it('caps the rewards one referrer earns in a month', function () {
    config(['journey.referrals.monthly_cap' => 2]);
    $referrer = User::factory()->create();
    $fulfil = function (User $friend) {
        $order = Order::query()->create([
            'user_id' => $friend->id, 'currency' => 'USD', 'method' => 'transfer', 'status' => 'awaiting_payment', 'total_cents' => 900,
            'items' => [['type' => 'addon', 'id' => 'template_unlock', 'name' => 'x', 'quantity' => 1, 'unit_amount' => 900, 'amount' => 900]],
        ]);
        Journey::pay($order);
    };

    foreach (range(1, 3) as $i) {
        $friend = User::factory()->create();
        Referral::query()->create(['referrer_user_id' => $referrer->id, 'referred_user_id' => $friend->id]);
        $fulfil($friend);
    }

    expect(Referral::query()->where('referrer_user_id', $referrer->id)->pluck('reward_status')->sort()->values()->all())->toBe(['capped', 'rewarded', 'rewarded'])
        ->and(app(Credits::class)->balance($referrer, 'USD'))->toBe(1000);
});

it('spends account credit at checkout, holds it while unpaid and restores it on refund', function () {
    $user = User::factory()->create();
    app(Credits::class)->add($user, 'SAR', 1900, AccountCredit::REFERRAL_REWARD);
    $event = Payments::event($user);

    $this->actingAs($user)->post('/checkout', ['event_id' => $event->id, 'plan' => 'premium', 'currency' => 'SAR', 'gateway' => 'transfer', 'use_credit' => true])
        ->assertSessionHasNoErrors();
    $order = Order::query()->where('event_id', $event->id)->firstOrFail();

    expect(collect($order->items)->last())->toMatchArray(['type' => 'referral_credit', 'amount' => -1900])
        ->and($order->total_cents)->toBe(10900 - 1900)
        ->and(app(Credits::class)->available($user, 'SAR'))->toBe(0)
        ->and(app(Credits::class)->balance($user, 'SAR'))->toBe(1900);

    Journey::pay($order);
    expect(app(Credits::class)->balance($user, 'SAR'))->toBe(0);
    $invoice = Invoice::query()->where('order_id', $order->id)->first();
    expect(collect($invoice->lines)->last())->toMatchArray(['type' => 'referral_credit', 'amount' => -1900, 'name_en' => 'From your account credit']);

    app(OrderFulfillment::class)->refund($order->fresh(), User::factory()->admin()->create());
    expect(app(Credits::class)->balance($user, 'SAR'))->toBe(1900);
});

it('shows the invite-friends panel on the billing tab', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/account/billing')->assertOk()
        ->assertInertia(fn ($page) => $page->where('billing.journey.link', fn ($link) => str_contains($link, '/r/'))->where('billing.journey.url', '/account/journey'));
});
