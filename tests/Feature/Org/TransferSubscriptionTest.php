<?php

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrgSubscription;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\OrgSubscriptionNotice;
use App\Services\Org\OrgSubscriptions;
use App\Services\Payments\OrderFulfillment;
use Illuminate\Support\Facades\Notification;
use Tests\Support\OrgFixtures;

beforeEach(function () {
    OrgFixtures::setUp();
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
});

function approve(Order $order): void
{
    app(OrderFulfillment::class)->approveTransfer($order, test()->admin, 'BANK-'.$order->code);
}

it('starts a transfer subscription when the admin approves the first order, with an invoice and a payment', function () {
    [$org, $owner] = OrgFixtures::org();

    $response = $this->actingAs($owner)->post("/org/{$org->id}/billing/subscribe", ['plan' => 'venue_pro', 'gateway' => 'transfer', 'currency' => 'SAR']);
    $order = Order::query()->sole();
    $response->assertRedirect("/dashboard/orders/{$order->code}");

    expect($order->only('org_id', 'event_id', 'method', 'currency', 'total_cents', 'status'))
        ->toBe(['org_id' => $org->id, 'event_id' => null, 'method' => 'transfer', 'currency' => 'SAR', 'total_cents' => 36900, 'status' => 'awaiting_payment'])
        ->and($order->items[0])->toMatchArray(['type' => 'subscription', 'id' => 'venue_pro', 'org_id' => $org->id])
        ->and($order->expires_at->diffInHours(now(), true))->toBeGreaterThan(47.9)
        ->and($org->fresh()->plan_id)->toBeNull();

    approve($order);

    $subscription = OrgSubscription::query()->sole();
    expect($subscription->only('status', 'provider', 'plan_id', 'interval', 'currency', 'amount_cents'))
        ->toBe(['status' => 'active', 'provider' => 'transfer', 'plan_id' => 'venue_pro', 'interval' => 'month', 'currency' => 'SAR', 'amount_cents' => 36900])
        ->and($subscription->current_period_end->isSameDay(now()->addMonthNoOverflow()))->toBeTrue()
        ->and($org->fresh()->plan_id)->toBe('venue_pro')
        ->and(Invoice::query()->where('order_id', $order->id)->exists())->toBeTrue()
        ->and(Payment::query()->where('order_id', $order->id)->sole()->provider)->toBe('transfer')
        ->and(AuditLog::query()->where('action', 'org_subscription.started')->exists())->toBeTrue();

    // the invoice line is the subscription, by name
    expect(Invoice::query()->sole()->lines[0])->toMatchArray(['type' => 'subscription', 'id' => 'venue_pro']);
});

it('runs the renewal cycle: order 7 days before, approval extends from the period end', function () {
    [$org, $owner] = OrgFixtures::org();
    $subscription = OrgFixtures::subscribe($org);
    $end = $subscription->current_period_end;

    // more than 7 days left: nothing yet
    $this->travelTo($end->subDays(8));
    $this->artisan('org:renewal-orders')->assertSuccessful();
    expect(Order::query()->count())->toBe(0);

    $this->travelTo($end->subDays(7)->addHour());
    $this->artisan('org:renewal-orders')->assertSuccessful();
    $this->artisan('org:renewal-orders')->assertSuccessful();
    $order = Order::query()->sole();
    expect($order->items[0])->toMatchArray(['subscription_id' => $subscription->id, 'period_end' => $end->toIso8601String()])
        ->and($order->user_id)->toBe($owner->id);
    Notification::assertSentToTimes($owner, OrgSubscriptionNotice::class, 1);

    approve($order);
    expect($subscription->fresh()->current_period_end->equalTo($end->addMonthNoOverflow()))->toBeTrue()
        ->and($subscription->fresh()->status)->toBe('active');

    // the next run makes no second order for the same period
    $this->artisan('org:renewal-orders')->assertSuccessful();
    expect(Order::query()->count())->toBe(1);
});

it('sends the 7-day and 1-day reminders once each', function () {
    [$org, $owner] = OrgFixtures::org();
    $subscription = OrgFixtures::subscribe($org);
    $end = $subscription->current_period_end;

    $this->travelTo($end->subDays(6));
    $this->artisan('org:renewal-reminders');
    $this->artisan('org:renewal-reminders');
    Notification::assertSentToTimes($owner, OrgSubscriptionNotice::class, 1);
    Notification::assertSentTo($owner, OrgSubscriptionNotice::class, fn ($n) => $n->kind === 'renewal_7');

    $this->travelTo($end->subHours(12));
    $this->artisan('org:renewal-reminders');
    $this->artisan('org:renewal-reminders');
    Notification::assertSentToTimes($owner, OrgSubscriptionNotice::class, 2);
    Notification::assertSentTo($owner, OrgSubscriptionNotice::class, fn ($n) => $n->kind === 'renewal_1');
});

it('moves a missed renewal to past_due, then expired after the grace period, and downgrades the org', function () {
    [$org, $owner] = OrgFixtures::org();
    $subscription = OrgFixtures::subscribe($org);
    $end = $subscription->current_period_end;

    $this->travelTo($end->addMinute());
    $this->artisan('org:expire-subscriptions')->assertSuccessful();
    expect($subscription->fresh()->status)->toBe('past_due')
        // still on during the grace period
        ->and($org->fresh()->plan_id)->toBe('venue_pro');
    Notification::assertSentTo($owner, OrgSubscriptionNotice::class, fn ($n) => $n->kind === 'past_due');

    $this->travelTo($end->addDays(6));
    $this->artisan('org:expire-subscriptions');
    expect($subscription->fresh()->status)->toBe('past_due');

    $this->travelTo($end->addDays(7)->addHour());
    $this->artisan('org:expire-subscriptions');
    $this->artisan('org:expire-subscriptions');
    expect($subscription->fresh()->status)->toBe('expired')
        ->and($subscription->fresh()->ended_at)->not->toBeNull()
        ->and($org->fresh()->plan_id)->toBeNull();
    Notification::assertSentToTimes($owner, OrgSubscriptionNotice::class, 2);
});

it('keeps the period continuous when the renewal is paid during the grace period', function () {
    [$org] = OrgFixtures::org();
    $subscription = OrgFixtures::subscribe($org);
    $end = $subscription->current_period_end;

    $this->travelTo($end->addDays(2));
    app(OrgSubscriptions::class)->markOverdue();
    $order = app(OrgSubscriptions::class)->renewalOrder($subscription->fresh(), again: true);
    approve($order);

    expect($subscription->fresh()->status)->toBe('active')
        ->and($subscription->fresh()->past_due_since)->toBeNull()
        ->and($subscription->fresh()->current_period_end->equalTo($end->addMonthNoOverflow()))->toBeTrue();
});

it('applies a tier change at the next transfer period', function () {
    [$org, $owner] = OrgFixtures::org();
    $subscription = OrgFixtures::subscribe($org);

    $this->actingAs($owner)->post("/org/{$org->id}/billing/change", ['plan' => 'venue_business'])->assertSessionHasNoErrors();
    expect($subscription->fresh()->only('plan_id', 'next_plan_id'))->toBe(['plan_id' => 'venue_pro', 'next_plan_id' => 'venue_business'])
        ->and($org->fresh()->plan_id)->toBe('venue_pro');

    $this->travelTo($subscription->current_period_end->subDays(3));
    $this->artisan('org:renewal-orders');
    $order = Order::query()->sole();
    expect($order->items[0]['id'])->toBe('venue_business')->and($order->total_cents)->toBe(55900);

    approve($order);
    expect($subscription->fresh()->only('plan_id', 'next_plan_id'))->toBe(['plan_id' => 'venue_business', 'next_plan_id' => null])
        ->and($org->fresh()->plan_id)->toBe('venue_business');
});

it('cancels at period end without a renewal, and can be resumed before then', function () {
    [$org, $owner] = OrgFixtures::org();
    $subscription = OrgFixtures::subscribe($org);

    $this->actingAs($owner)->post("/org/{$org->id}/billing/cancel")->assertSessionHasNoErrors();
    expect($subscription->fresh()->cancel_at_period_end)->toBeTrue();

    $this->actingAs($owner)->post("/org/{$org->id}/billing/resume")->assertSessionHasNoErrors();
    expect($subscription->fresh()->cancel_at_period_end)->toBeFalse();

    $this->actingAs($owner)->post("/org/{$org->id}/billing/cancel");
    $this->travelTo($subscription->current_period_end->subDays(2));
    $this->artisan('org:renewal-orders');
    expect(Order::query()->count())->toBe(0);

    $this->travelTo($subscription->current_period_end->addMinute());
    $this->artisan('org:expire-subscriptions');
    expect($subscription->fresh()->status)->toBe('canceled')->and($org->fresh()->plan_id)->toBeNull();
});

it('refuses a second subscription and keeps billing to the owner', function () {
    [$org, $owner] = OrgFixtures::org();
    $staff = OrgFixtures::member($org);
    OrgFixtures::subscribe($org);

    $this->actingAs($owner)->post("/org/{$org->id}/billing/subscribe", ['plan' => 'venue_pro', 'gateway' => 'transfer', 'currency' => 'SAR'])
        ->assertSessionHasErrors('plan');
    $this->actingAs($staff)->get("/org/{$org->id}/billing")->assertForbidden();
    $this->actingAs($staff)->post("/org/{$org->id}/billing/cancel")->assertForbidden();
    $this->actingAs($owner)->get("/org/{$org->id}/billing")->assertOk()
        ->assertInertia(fn ($page) => $page->component('org/Billing')->has('tiers', 3)->where('org.subscription.plan_id', 'venue_pro'));
});
