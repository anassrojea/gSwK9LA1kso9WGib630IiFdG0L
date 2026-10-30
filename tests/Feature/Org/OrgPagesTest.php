<?php

use App\Models\Event;
use App\Models\HallLayout;
use App\Models\Household;
use App\Models\Organization;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\HallGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\OrgFixtures;

beforeEach(function () {
    OrgFixtures::setUp();
    Notification::fake();
});

it('creates an organization with a logo, the creator as its owner', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    $this->actingAs($user)->get('/org/create')->assertOk()->assertInertia(fn ($page) => $page->component('org/Create'));
    $this->actingAs($user)->post('/org', [
        'name' => 'قاعة الياسمين',
        'type' => 'venue',
        'city' => 'دمشق',
        'contact_phone' => '+963 11 222 3333',
        'contact_email' => 'hall@example.com',
        'logo' => UploadedFile::fake()->image('logo.png', 120, 120),
    ])->assertRedirect();

    $org = Organization::query()->sole();
    expect($org->only('name', 'type', 'city', 'contact_email'))->toBe(['name' => 'قاعة الياسمين', 'type' => 'venue', 'city' => 'دمشق', 'contact_email' => 'hall@example.com'])
        ->and($user->roleIn($org))->toBe('owner')
        ->and($org->logo_url)->not->toBeNull();

    $this->actingAs($user)->get('/org')->assertInertia(fn ($page) => $page->component('org/Index')->has('organizations', 1)->where('organizations.0.role', 'owner'));

    // an SVG logo could carry script: refused
    $this->actingAs($user)->post('/org', ['name' => 'X hall', 'type' => 'venue', 'logo' => UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml')])
        ->assertSessionHasErrors('logo');
});

it('shows the venue calendar of the month, the team and the halls', function () {
    [$org, $owner] = OrgFixtures::org();
    $staff = OrgFixtures::member($org);
    OrgFixtures::subscribe($org);
    $inMonth = OrgFixtures::event($org, $staff, ['starts_at' => now()->startOfMonth()->addDays(10), 'title' => 'This month']);
    $nextMonth = OrgFixtures::event($org, $staff, ['starts_at' => now()->startOfMonth()->addMonths(2), 'title' => 'Later']);
    Event::factory()->create(['title' => 'Not ours', 'starts_at' => now()]);
    HallLayout::query()->create(['org_id' => $org->id, 'name' => 'Ballroom', 'layout' => app(HallGenerator::class)->generate(4, 8)]);

    $this->actingAs($staff)->get("/org/{$org->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('org/Show')
        ->where('month', now()->format('Y-m'))
        ->has('events', 1)
        ->where('events.0.id', $inMonth->id)
        ->has('team', 2)
        ->where('team.0.role', 'owner')
        ->has('halls', 1)
        ->where('halls.0.name', 'Ballroom')
        ->where('org.quota.active', true)
        ->where('org.can.billing', false)
        ->where('org.can.create_events', true));

    $later = now()->startOfMonth()->addMonths(2)->format('Y-m');
    $this->actingAs($staff)->get("/org/{$org->id}?month={$later}")->assertInertia(fn ($page) => $page->has('events', 1)->where('events.0.id', $nextMonth->id));

    $this->actingAs(User::factory()->create())->get("/org/{$org->id}")->assertForbidden();
});

it('creates a client event owned by the org and opens it in the wizard', function () {
    [$org, $owner] = OrgFixtures::org();
    $door = OrgFixtures::member($org, 'door');

    $this->actingAs($door)->post("/org/{$org->id}/events", ['occasion' => 'wedding'])->assertForbidden();

    $response = $this->actingAs($owner)->post("/org/{$org->id}/events", ['occasion' => 'henna']);
    $event = Event::query()->sole();
    $response->assertRedirect("/create/{$event->id}/design");
    expect($event->only('org_id', 'owner_id', 'occasion', 'status'))->toBe(['org_id' => $org->id, 'owner_id' => $owner->id, 'occasion' => 'henna', 'status' => 'draft']);
});

it('shows the per-event view: guests, meals per section, arrivals and the seating PDF link', function () {
    [$org, $owner] = OrgFixtures::org();
    OrgFixtures::subscribe($org);
    $event = OrgFixtures::event($org, $owner, ['status' => 'active']);
    $household = Household::factory()->for($event)->seats(4)->create(['section' => 'men']);
    Rsvp::query()->create(['household_id' => $household->id, 'attending' => true, 'people_count' => 3, 'kids_count' => 1, 'responded_at' => now()]);

    $this->actingAs($owner)->get("/org/{$org->id}/events/{$event->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('org/Event')
        ->where('stats.attending', 3)
        ->where('meals.sections.men.adults', 2)
        ->where('meals.sections.men.kids', 1)
        ->where('arrivals.sections.men.expected', 3)
        ->where('features.seating', true)
        ->where('urls.seating_pdf', "/events/{$event->id}/seating.pdf"));
});

it('limits saved hall layouts to the plan', function () {
    [$org, $owner] = OrgFixtures::org();
    OrgFixtures::subscribe($org, 'venue_starter');
    $layout = app(HallGenerator::class)->generate(4, 8);

    foreach (['A', 'B'] as $name) {
        $this->actingAs($owner)->post('/dashboard/halls', ['org_id' => $org->id, 'name' => $name, 'layout' => $layout])->assertSessionHasNoErrors();
    }
    $this->actingAs($owner)->post('/dashboard/halls', ['org_id' => $org->id, 'name' => 'C', 'layout' => $layout])->assertSessionHasErrors('name');
    expect(HallLayout::query()->where('org_id', $org->id)->count())->toBe(2);
});

it('links the organizations from the account menu dictionary', function () {
    expect(trans('app.org_nav.organizations', [], 'ar'))->toBe('منشآتي')
        ->and(trans('app.org_nav.organizations', [], 'en'))->toBe('My organizations');
});
