<?php

use App\Models\Checkin;
use App\Models\Event;
use App\Models\SeatAssignment;
use App\Services\Dashboard\GuestBoard;
use Carbon\CarbonImmutable;
use Tests\Support\DashboardFixtures;

it('computes the top numbers: opened, replied, attending, seats left', function () {
    [$event] = DashboardFixtures::event();

    expect(app(GuestBoard::class)->stats($event))->toMatchArray([
        'invited' => 5,
        'opened' => 4,             // three opens plus a reply without a logged open
        'replied' => 3,
        'attending' => 5,          // people, not families
        'attending_families' => 2,
        'kids' => 1,
        'declined' => 1,
        'opened_not_replied' => 1,
        'never_opened' => 1,
        'seats_allowed' => 13,
        'seats_left' => 8,         // 13 seats handed out, 5 people confirmed
        'guest_limit' => 20,
        'limit_left' => 7,
        'arrived_families' => 0,
        'arrived_people' => 0,
    ]);
});

it('gives each row its status chip, people and table', function () {
    [$event, $h] = DashboardFixtures::event();
    SeatAssignment::query()->create(['event_id' => $event->id, 'household_id' => $h['attending']->id, 'table_id' => 'T7', 'seat_index' => 0]);

    $rows = collect(app(GuestBoard::class)->rows($event))->keyBy('name');

    expect($rows->map(fn ($r) => $r['status'])->all())->toBe([
        'عائلة لم تفتح' => 'not_opened',
        'عائلة فتحت' => 'opened',
        'عائلة ستحضر' => 'attending',
        'عائلة اعتذرت' => 'declined',
        'Omar Family' => 'attending',
    ])
        ->and($rows['عائلة ستحضر']['people'])->toBe(3)
        ->and($rows['عائلة ستحضر']['table'])->toBe('T7')
        ->and($rows['عائلة اعتذرت']['people'])->toBeNull()
        ->and($rows['عائلة لم تفتح']['last_opened_at'])->toBeNull()
        ->and($rows['عائلة فتحت']['last_opened_at'])->not->toBeNull()
        ->and($rows['عائلة لم تفتح']['link'])->toEndWith('/i/'.$h['not_opened']->token)
        ->and($rows['عائلة لم تفتح']['whatsapp_url'])->toStartWith('https://wa.me/962791111111?text=')
        ->and($rows['عائلة فتحت']['whatsapp_url'])->toStartWith('https://wa.me/?text=');
});

it('renders the event dashboard for the owner', function () {
    [$event] = DashboardFixtures::event();

    $this->actingAs($event->owner)
        ->get("/dashboard/events/{$event->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard/Event')
            ->where('event.id', $event->id)
            ->where('stats.replied', 3)
            ->where('stats.seats_left', 8)
            ->has('guests', 5)
            ->where('tags', ['أصدقاء', 'أقارب', 'جيران'])
            ->where('can.update', true)
            ->where('can.check_in', true)
            ->where('urls.edit', "/create/{$event->id}/details"));
});

it('sends drafts back to the wizard', function () {
    $event = Event::factory()->draft()->create();

    $this->actingAs($event->owner)->get("/dashboard/events/{$event->id}")->assertRedirect("/create/{$event->id}/details");
});

it('links each published event to its dashboard from the events list', function () {
    [$event] = DashboardFixtures::event();
    $draft = Event::factory()->draft()->create(['owner_id' => $event->owner_id]);

    $response = $this->actingAs($event->owner)->get('/dashboard')->assertInertia(fn ($page) => $page->component('Dashboard')->has('events', 2));

    $events = collect($response->viewData('page')['props']['events'])->keyBy('id');
    expect($events[$event->id]['dashboard_url'])->toBe("/dashboard/events/{$event->id}")
        ->and($events[$draft->id]['dashboard_url'])->toBeNull();
});

it('counts days left in the event timezone and spots the event day', function () {
    // 23:30 in Riyadh on 1 Nov is still 31 Oct in UTC
    $event = Event::factory()->make(['starts_at' => CarbonImmutable::parse('2026-11-01 20:30:00', 'UTC'), 'timezone' => 'Asia/Riyadh']);

    expect(GuestBoard::daysLeft($event, CarbonImmutable::parse('2026-10-31 21:30:00', 'UTC')))->toBe(0)
        ->and(GuestBoard::daysLeft($event, CarbonImmutable::parse('2026-10-31 20:00:00', 'UTC')))->toBe(1)
        ->and(GuestBoard::daysLeft($event, CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC')))->toBe(12)
        ->and(GuestBoard::daysLeft($event, CarbonImmutable::parse('2026-11-03 08:00:00', 'UTC')))->toBe(-2);
});

it('shows live day mode with an arrivals counter on the event day', function () {
    [$event, $h] = DashboardFixtures::event(['starts_at' => now()->setTime(12, 0), 'timezone' => 'UTC']);
    Checkin::query()->create(['event_id' => $event->id, 'household_id' => $h['attending']->id, 'people_in' => 3, 'scanned_at' => now()]);

    $this->actingAs($event->owner)->get("/dashboard/events/{$event->id}")->assertInertia(fn ($page) => $page
        ->where('stats.is_event_day', true)
        ->where('stats.days_left', 0)
        ->where('stats.arrived_families', 1)
        ->where('stats.arrived_people', 3));
});

it('saves the host\'s own WhatsApp wording and uses it in the links', function () {
    [$event, $h] = DashboardFixtures::event();

    $this->actingAs($event->owner)
        ->patch("/dashboard/events/{$event->id}/messages", ['invite' => 'يا :name تعالوا إلى :title :link', 'reminder' => ''])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($event->fresh()->messages)->toBe(['invite' => 'يا :name تعالوا إلى :title :link']);

    $row = collect(app(GuestBoard::class)->rows($event->fresh()))->firstWhere('id', $h['opened']->id);
    expect(urldecode($row['whatsapp_url']))->toContain('يا عائلة فتحت تعالوا إلى '.$event->title);
});

it('gives hosts the open link settings on the event dashboard, and hides them from door staff', function () {
    $event = \App\Models\Event::factory()->create(['status' => 'active']);
    \App\Models\Invitation::factory()->for($event)->create();

    $this->actingAs($event->owner)->get("/dashboard/events/{$event->id}")
        ->assertInertia(fn ($page) => $page->where('openLink.endpoint', fn ($url) => str_contains($url, "/events/{$event->id}/open-link")));

    $org = \App\Models\Organization::factory()->create();
    $event->update(['org_id' => $org->id]);
    $door = \App\Models\User::factory()->create();
    \App\Models\OrgMember::factory()->create(['org_id' => $org->id, 'user_id' => $door->id, 'role' => 'door']);

    $this->actingAs($door)->get("/dashboard/events/{$event->id}")->assertInertia(fn ($page) => $page->where('openLink', null));
});
