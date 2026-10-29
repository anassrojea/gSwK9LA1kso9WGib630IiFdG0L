<?php

use App\Models\Entitlement;
use App\Models\Event;
use App\Models\Household;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SeatAssignment;
use App\Services\Entitlements;
use Database\Seeders\PlanSeeder;

beforeEach(fn () => $this->seed(PlanSeeder::class));

it('unlocks the features of the event plan', function () {
    $free = Event::factory()->create(['plan_id' => 'free']);
    $signature = Event::factory()->create(['plan_id' => 'signature']);
    $entitlements = app(Entitlements::class);

    expect($entitlements->hasFeature($free, Entitlements::RSVP))->toBeTrue()
        ->and($entitlements->hasFeature($free, Entitlements::SEATING))->toBeFalse()
        ->and($entitlements->hasFeature($signature, Entitlements::SEATING))->toBeTrue()
        ->and($entitlements->hasFeature($signature, Entitlements::WALLET))->toBeTrue();
});

it('falls back to the free plan when an event has none', function () {
    $event = Event::factory()->create(['plan_id' => null]);

    expect(app(Entitlements::class)->hasFeature($event, Entitlements::PERSONAL_LINKS))->toBeFalse()
        ->and(app(Entitlements::class)->limit($event, 'guests'))->toBe(50);
});

it('unlocks add-ons from the entitlements table until they expire', function () {
    $event = Event::factory()->create(['plan_id' => 'premium']);
    Entitlement::factory()->create(['event_id' => $event->id, 'feature' => Entitlements::SEATING]);
    Entitlement::factory()->create(['event_id' => $event->id, 'feature' => Entitlements::PHOTO_WALL, 'until' => now()->subDay()]);
    Entitlement::factory()->create(['event_id' => $event->id, 'feature' => Entitlements::WALLET, 'until' => now()->addMonth()]);

    $entitlements = app(Entitlements::class);

    expect($entitlements->hasFeature($event, Entitlements::SEATING))->toBeTrue()
        ->and($entitlements->hasFeature($event, Entitlements::WALLET))->toBeTrue()
        ->and($entitlements->hasFeature($event, Entitlements::PHOTO_WALL))->toBeFalse()
        ->and($entitlements->features($event))->toContain(Entitlements::PERSONAL_LINKS, Entitlements::SEATING);
});

it('applies organization plans and organization-wide entitlements', function () {
    $org = Organization::factory()->create(['plan_id' => 'venue_pro']);
    $event = Event::factory()->forOrganization($org)->create(['plan_id' => null]);
    $other = Event::factory()->forOrganization(Organization::factory()->create())->create(['plan_id' => 'free']);
    Entitlement::factory()->create(['event_id' => null, 'org_id' => $other->org_id, 'feature' => Entitlements::QR_CHECKIN]);

    expect(app(Entitlements::class)->hasFeature($event, Entitlements::SEATING))->toBeTrue()
        ->and(app(Entitlements::class)->hasFeature($other, Entitlements::QR_CHECKIN))->toBeTrue()
        ->and(app(Entitlements::class)->hasFeature($other, Entitlements::SEATING))->toBeFalse();
});

it('checks the guest limit on write', function () {
    $event = Event::factory()->create(['plan_id' => 'free']);
    Household::factory()->for($event)->seats(48)->create();
    $entitlements = app(Entitlements::class);

    expect($entitlements->canAddGuests($event, 2))->toBeTrue()
        ->and($entitlements->canAddGuests($event, 3))->toBeFalse();

    $event->update(['guest_limit' => 500]);
    expect($entitlements->canAddGuests($event->refresh(), 3))->toBeTrue();
});

it('shows the table number only with the seating feature', function () {
    [$event, $household] = invitedHousehold(seats: 2);
    $event->update(['plan_id' => 'premium']);
    $this->postJson("/i/{$household->token}/rsvp", ['attending' => true, 'people_count' => 2])->assertOk();
    SeatAssignment::factory()->create(['event_id' => $event->id, 'household_id' => $household->id, 'table_id' => 'T7', 'seat_index' => 0]);

    $this->getJson("/i/{$household->token}/state")->assertJsonPath('table', null);

    Entitlement::factory()->create(['event_id' => $event->id, 'feature' => Entitlements::SEATING]);
    $this->getJson("/i/{$household->token}/state")->assertJsonPath('table', 'T7');
});

it('treats plans as data', function () {
    expect(Plan::query()->pluck('id')->sort()->values()->all())->toBe(['free', 'premium', 'signature', 'venue_pro']);
});
