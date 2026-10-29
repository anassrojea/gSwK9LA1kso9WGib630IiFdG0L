<?php

use App\Events\GuestCheckedIn;
use App\Models\Checkin;
use App\Models\OrgMember;
use Illuminate\Support\Facades\Event as Events;
use Tests\Support\DashboardFixtures;

it('edits name, seats, phone and tags', function () {
    [$event, $h] = DashboardFixtures::event();

    $this->actingAs($event->owner)
        ->from("/dashboard/events/{$event->id}")
        ->patch("/dashboard/households/{$h['opened']->id}", [
            'name' => '  أم   فيصل ',
            'seats' => 5,
            'phone' => '+962 ٧٩ 123-4567',
            'tags' => ['جيران', ' جيران ', 'أصدقاء'],
        ])
        ->assertRedirect("/dashboard/events/{$event->id}")
        ->assertSessionHasNoErrors();

    expect($h['opened']->fresh())
        ->display_name->toBe('أم فيصل')
        ->seats_allowed->toBe(5)
        ->phone->toBe('+962791234567')
        ->tags->toBe(['جيران', 'أصدقاء']);
});

it('validates the seats', function (mixed $seats) {
    [$event, $h] = DashboardFixtures::event();

    $this->actingAs($event->owner)
        ->patch("/dashboard/households/{$h['opened']->id}", ['name' => 'x', 'seats' => $seats])
        ->assertSessionHasErrors('seats');

    expect($h['opened']->fresh()->seats_allowed)->toBe(3);
})->with([0, -1, 101, 'two', null]);

it('refuses fewer seats than the family already confirmed', function () {
    [$event, $h] = DashboardFixtures::event();

    $this->actingAs($event->owner)
        ->patch("/dashboard/households/{$h['attending']->id}", ['name' => 'x', 'seats' => 2])
        ->assertSessionHasErrors('seats');

    expect($h['attending']->fresh()->seats_allowed)->toBe(4);
});

it('keeps seat edits inside the plan\'s guest limit', function () {
    [$event, $h] = DashboardFixtures::event(); // 13 of 20 seats used

    $this->actingAs($event->owner)
        ->patch("/dashboard/households/{$h['opened']->id}", ['name' => 'x', 'seats' => 11])
        ->assertSessionHasErrors('seats');

    $this->patch("/dashboard/households/{$h['opened']->id}", ['name' => 'x', 'seats' => 10])
        ->assertSessionHasNoErrors();
});

it('rejects a phone that is not a number', function () {
    [$event, $h] = DashboardFixtures::event();

    $this->actingAs($event->owner)
        ->patch("/dashboard/households/{$h['opened']->id}", ['name' => 'x', 'seats' => 1, 'phone' => 'call me'])
        ->assertSessionHasErrors('phone');
});

it('deletes a family', function () {
    [$event, $h] = DashboardFixtures::event();

    $this->actingAs($event->owner)->delete("/dashboard/households/{$h['declined']->id}")->assertRedirect();

    expect($event->households()->count())->toBe(4)
        ->and($h['declined']->fresh())->toBeNull();
});

it('marks a family as arrived once and warns on the second time', function () {
    Events::fake([GuestCheckedIn::class]);
    [$event, $h] = DashboardFixtures::event();

    $this->actingAs($event->owner)
        ->post("/dashboard/households/{$h['attending']->id}/checkin")
        ->assertRedirect()
        ->assertSessionHas('status');

    $this->post("/dashboard/households/{$h['attending']->id}/checkin")
        ->assertRedirect()
        ->assertSessionHas('warning')
        ->assertSessionMissing('status');

    $checkins = Checkin::query()->where('household_id', $h['attending']->id)->get();
    expect($checkins)->toHaveCount(1)
        ->and($checkins[0]->people_in)->toBe(3) // their RSVP
        ->and($checkins[0]->scanned_by)->toBe($event->owner_id);

    Events::assertDispatchedTimes(GuestCheckedIn::class, 1);
});

it('lets door staff view and check in, but not edit, delete or remind', function () {
    [$event, $h] = DashboardFixtures::event();
    $door = DashboardFixtures::member($event, OrgMember::ROLE_DOOR);

    $this->actingAs($door)->get("/dashboard/events/{$event->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.update', false)->where('can.check_in', true));

    $this->post("/dashboard/households/{$h['opened']->id}/checkin", ['people' => 2])->assertRedirect()->assertSessionHas('status');
    expect(Checkin::query()->where('household_id', $h['opened']->id)->value('people_in'))->toBe(2);

    $this->patch("/dashboard/households/{$h['opened']->id}", ['name' => 'x', 'seats' => 1])->assertForbidden();
    $this->delete("/dashboard/households/{$h['opened']->id}")->assertForbidden();
    $this->postJson("/dashboard/events/{$event->id}/reminders", ['audience' => 'both'])->assertForbidden();
    $this->patch("/dashboard/events/{$event->id}/messages", ['invite' => 'x'])->assertForbidden();
    $this->get("/dashboard/events/{$event->id}/guests.csv")->assertOk();
});

it('lets org staff manage the guests', function () {
    [$event, $h] = DashboardFixtures::event();
    $staff = DashboardFixtures::member($event, OrgMember::ROLE_STAFF);

    $this->actingAs($staff)->get("/dashboard/events/{$event->id}")->assertInertia(fn ($page) => $page->where('can.update', true));
    $this->patch("/dashboard/households/{$h['opened']->id}", ['name' => 'x', 'seats' => 1])->assertSessionHasNoErrors();
});
