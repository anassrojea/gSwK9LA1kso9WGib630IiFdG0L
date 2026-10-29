<?php

use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\OrgMember;
use App\Models\User;
use App\Services\Account\UpgradeHints;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlanSeeder;
use Tests\Support\DashboardFixtures;
use Tests\Support\Payments;

beforeEach(fn () => $this->seed([PlanSeeder::class, CatalogSeeder::class]));

function liveEvent(string $plan, ?User $owner = null): Event
{
    $event = Event::factory()->create(['plan_id' => $plan, 'status' => 'active', 'owner_id' => ($owner ?? User::factory()->create())->id]);
    Invitation::factory()->for($event)->create(['config' => Payments::freeConfig(), 'published_at' => now()]);

    return $event;
}

it('shows each event\'s plan and an upgrade link on the events list', function () {
    $user = User::factory()->create();
    $free = liveEvent('free', $user);
    $premium = liveEvent('premium', $user);
    $signature = liveEvent('signature', $user);

    $this->actingAs($user)->get('/dashboard')->assertInertia(function ($page) use ($free, $premium, $signature) {
        $events = collect($page->toArray()['props']['events'])->keyBy('id');

        expect($events[$free->id])->toMatchArray(['plan' => 'free', 'upgrade_url' => "/dashboard/events/{$free->id}/upgrade"])
            ->and($events[$premium->id])->toMatchArray(['plan' => 'premium', 'upgrade_url' => "/dashboard/events/{$premium->id}/upgrade"])
            // the top plan has nothing to upgrade to
            ->and($events[$signature->id])->toMatchArray(['plan' => 'signature', 'upgrade_url' => null]);
    });
});

it('offers no upgrade link to door staff, who can\'t change the event', function () {
    $event = liveEvent('free');
    $door = DashboardFixtures::member($event, OrgMember::ROLE_DOOR);

    $this->actingAs($door)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('events.0.plan', 'free')->where('events.0.upgrade_url', null));
});

it('shows the upgrade banner on a Free event dashboard, with what Premium unlocks', function () {
    $event = liveEvent('free');

    $this->actingAs($event->owner)->get("/dashboard/events/{$event->id}")
        ->assertInertia(fn ($page) => $page
            ->where('upgradeHint.plan', 'free')
            ->where('upgradeHint.next', 'premium')
            ->where('upgradeHint.missing', [])
            ->where('upgradeHint.unlocks', fn ($unlocks) => collect($unlocks)->contains('personal_links') && collect($unlocks)->contains('reminders') && count($unlocks) <= 4)
            ->where('upgradeHint.next_guests', 300));
});

it('hides the banner on a Premium event the plan covers', function () {
    $event = liveEvent('premium');

    $this->actingAs($event->owner)->get("/dashboard/events/{$event->id}")->assertInertia(fn ($page) => $page->where('upgradeHint', null));
});

it('shows the banner on a published event its plan no longer covers', function () {
    $event = liveEvent('premium');
    // more people than the free 50 (so the plan must cover them) and than this event's limit
    $event->update(['guest_limit' => 55]);
    Household::factory()->for($event)->seats(60)->create();

    $this->actingAs($event->owner)->get("/dashboard/events/{$event->id}")
        ->assertInertia(fn ($page) => $page->where('upgradeHint.plan', 'premium')->where('upgradeHint.missing', ['guests']));
});

it('shows no banner to door staff', function () {
    $event = liveEvent('free');
    $door = DashboardFixtures::member($event, OrgMember::ROLE_DOOR);

    $this->actingAs($door)->get("/dashboard/events/{$event->id}")->assertInertia(fn ($page) => $page->where('upgradeHint', null));
});

it('lists plans, what they include and what the next one adds on the billing tab', function () {
    $user = User::factory()->create();
    $free = liveEvent('free', $user);
    $draft = Payments::event($user);

    $this->actingAs($user)->get('/account/billing')->assertInertia(function ($page) use ($free, $draft) {
        $events = collect($page->toArray()['props']['billing']['events'])->keyBy('id');

        expect($events[$free->id])->toMatchArray(['plan' => 'free', 'next' => 'premium', 'upgrade_url' => "/dashboard/events/{$free->id}/upgrade"])
            ->and($events[$free->id]['features'])->toContain('rsvp', 'open_link')
            ->and($events[$free->id]['unlocks'])->toContain('personal_links')
            // a draft picks its plan at the wizard's review step
            ->and($events[$draft->id]['upgrade_url'])->toBe("/create/{$draft->id}/review");
    });
});

it('lists pending transfers with their code on the billing tab', function () {
    Payments::setUp();
    $user = User::factory()->create();
    $event = liveEvent('free', $user);
    $order = Order::factory()->create(['user_id' => $user->id, 'event_id' => $event->id, 'currency' => 'SAR']);

    $this->actingAs($user)->get('/account/billing')
        ->assertInertia(fn ($page) => $page
            ->where('billing.pending.0.code', $order->code)
            ->where('billing.pending.0.transfer.whatsapp_url', fn ($url) => str_contains((string) $url, 'wa.me'))
            ->where('billing.orders.0.code', $order->code));
});

it('names the next plan up', function () {
    $hints = app(UpgradeHints::class);

    expect($hints->nextPlan('free'))->toBe('premium')
        ->and($hints->nextPlan('premium'))->toBe('signature')
        ->and($hints->nextPlan('signature'))->toBeNull()
        ->and($hints->nextPlan('venue_pro'))->toBeNull();
});
