<?php

use App\Events\GuestCheckedIn;
use App\Models\Checkin;
use App\Models\OrgMember;
use App\Models\SeatAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\DashboardFixtures;
use Tests\Support\SeatingFixtures;

function p7aScan(array $extra = []): array
{
    return $extra + ['scan_id' => (string) Str::uuid()];
}

it('opens the door app for the owner and organization owner, staff and door members only', function () {
    [$event] = SeatingFixtures::event([['Ali', 2]]);

    $this->actingAs($event->owner)->get("/door/{$event->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('door/Door')->where('locked', false)->has('guests', 1)->where('actor.live', true));

    foreach ([OrgMember::ROLE_OWNER, OrgMember::ROLE_STAFF, OrgMember::ROLE_DOOR] as $role) {
        $this->actingAs(DashboardFixtures::member($event, $role))->get("/door/{$event->id}")->assertOk();
    }

    $this->actingAs(User::factory()->create())->get("/door/{$event->id}")->assertForbidden();
    auth()->logout();
    $this->get("/door/{$event->id}")->assertRedirect('/login');
    $this->postJson("/door/{$event->id}/checkin", p7aScan(['token' => 'x']))->assertUnauthorized();
});

it('is part of the QR check-in feature', function () {
    [$event, $h] = DashboardFixtures::event();

    $this->actingAs($event->owner)->get("/door/{$event->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('locked', true)->where('guests', [])->where('urls.upgrade', "/dashboard/events/{$event->id}/upgrade?addon=qr_checkin"));
    $this->actingAs($event->owner)->postJson("/door/{$event->id}/checkin", p7aScan(['token' => $h['attending']->token]))->assertForbidden();

    // marking arrivals from the dashboard stays available to every plan
    $this->actingAs($event->owner)->post("/dashboard/households/{$h['attending']->id}/checkin")->assertRedirect();
    expect(Checkin::query()->count())->toBe(1);
});

it('checks a family in from the scanned link with their table and section, and broadcasts it', function () {
    Events::fake([GuestCheckedIn::class]);
    [$event, $f] = SeatingFixtures::event([['Ali', 3, 1, 'men']]);
    SeatAssignment::query()->create(['event_id' => $event->id, 'household_id' => $f['Ali']->id, 'table_id' => 'T2', 'seat_index' => 0]);
    $door = DashboardFixtures::member($event, OrgMember::ROLE_DOOR);

    $response = $this->actingAs($door)
        ->postJson("/door/{$event->id}/checkin", p7aScan(['token' => "https://daawa.link/i/{$f['Ali']->token}", 'people_in' => 3]))
        ->assertOk()
        ->assertJsonPath('status', 'checked_in')
        ->assertJsonPath('guest.name', 'Ali')
        ->assertJsonPath('guest.table', '2')
        ->assertJsonPath('guest.section', 'men')
        ->assertJsonPath('guest.people', 3)
        ->assertJsonPath('guest.in', 3)
        ->assertJsonPath('stats.sections.men.arrived', 3)
        ->assertJsonPath('stats.total.expected', 3);

    expect($response->json('warnings'))->toBe([])
        ->and(Checkin::query()->first()->scanned_by)->toBe($door->id);
    Events::assertDispatched(GuestCheckedIn::class);
});

it('asks for a confirmation on a second entry and says when and by whom the first was', function () {
    [$event, $f] = SeatingFixtures::event([['Ali', 2]]);
    $this->actingAs($event->owner)->postJson("/door/{$event->id}/checkin", p7aScan(['token' => $f['Ali']->token, 'people_in' => 2]))->assertOk();

    $again = $this->actingAs($event->owner)->postJson("/door/{$event->id}/checkin", p7aScan(['token' => $f['Ali']->token, 'people_in' => 1]))
        ->assertStatus(409)
        ->assertJsonPath('status', 'needs_confirmation')
        ->assertJsonPath('warnings.0.type', 'already_in')
        ->assertJsonPath('warnings.0.people_in', 2)
        ->assertJsonPath('warnings.0.by', $event->owner->name);
    expect($again->json('warnings.0.time'))->toMatch('/^\d\d:\d\d$/')
        ->and(Checkin::query()->count())->toBe(1);

    $this->actingAs($event->owner)->postJson("/door/{$event->id}/checkin", p7aScan(['token' => $f['Ali']->token, 'people_in' => 1, 'confirm' => true]))
        ->assertOk()->assertJsonPath('status', 'checked_in')->assertJsonPath('guest.in', 3);
});

it('warns on more people than seats, on fewer or more than confirmed, and on families that did not say yes', function () {
    [$event, $f] = SeatingFixtures::event([['Ali', 2], ['Silent', 0]]); // Ali: 3 seats, 2 coming
    $post = fn (array $body) => $this->actingAs($event->owner)->postJson("/door/{$event->id}/checkin", p7aScan($body));

    $post(['token' => $f['Ali']->token, 'people_in' => 5])->assertStatus(409)
        ->assertJsonPath('warnings.0', ['type' => 'over_limit', 'allowed' => 3, 'total' => 5]);
    $post(['token' => $f['Ali']->token, 'people_in' => 1])->assertStatus(409)->assertJsonPath('warnings.0.type', 'partial');
    $post(['token' => $f['Ali']->token, 'people_in' => 3])->assertStatus(409)->assertJsonPath('warnings.0.type', 'extra');
    $post(['token' => $f['Silent']->token])->assertStatus(409)->assertJsonPath('warnings.0', ['type' => 'not_attending', 'status' => 'no_reply']);

    // without people_in, the remaining confirmed people go in
    $post(['token' => $f['Ali']->token])->assertOk()->assertJsonPath('checkin.people_in', 2);
    expect(Checkin::query()->count())->toBe(1);
});

it('records a scan once, however often the device sends it', function () {
    Events::fake([GuestCheckedIn::class]);
    [$event, $f] = SeatingFixtures::event([['Ali', 2]]);
    $body = p7aScan(['token' => $f['Ali']->token, 'people_in' => 2]);

    $this->actingAs($event->owner)->postJson("/door/{$event->id}/checkin", $body)->assertOk()->assertJsonPath('status', 'checked_in');
    $this->actingAs($event->owner)->postJson("/door/{$event->id}/checkin", $body)->assertOk()->assertJsonPath('status', 'already_recorded');
    $this->actingAs($event->owner)->postJson("/door/{$event->id}/checkin", $body + ['confirm' => true])->assertOk()->assertJsonPath('status', 'already_recorded');

    expect(Checkin::query()->count())->toBe(1);
    Events::assertDispatchedTimes(GuestCheckedIn::class, 1);
});

it('does not find families of other events or unknown codes', function () {
    [$event] = SeatingFixtures::event([['Ali', 2]]);
    [, $other] = SeatingFixtures::event([['Elsewhere', 2]]);

    $this->actingAs($event->owner)->postJson("/door/{$event->id}/checkin", p7aScan(['token' => $other['Elsewhere']->token]))->assertNotFound();
    $this->actingAs($event->owner)->postJson("/door/{$event->id}/checkin", p7aScan(['token' => 'https://evil.example/whatever']))->assertNotFound();
    $this->actingAs($event->owner)->postJson("/door/{$event->id}/checkin", ['token' => $other['Elsewhere']->token])->assertUnprocessable();
});

it('syncs offline scans and flags conflicts instead of recording them', function () {
    Events::fake([GuestCheckedIn::class]);
    [$event, $f] = SeatingFixtures::event([['Ali', 2], ['Huda', 2], ['Omar', 2]]);

    // Huda was let in on another phone while this one was offline
    $this->actingAs($event->owner)->postJson("/door/{$event->id}/checkin", p7aScan(['token' => $f['Huda']->token, 'people_in' => 2]))->assertOk();

    $synced = p7aScan(['token' => $f['Ali']->token, 'people_in' => 2, 'scanned_at' => now()->subMinutes(10)->toIso8601String(), 'known_in' => 0]);
    $scans = [
        $synced,
        p7aScan(['token' => $f['Huda']->token, 'people_in' => 2, 'known_in' => 0]),
        p7aScan(['token' => $f['Omar']->token, 'people_in' => 6, 'known_in' => 0]),
        p7aScan(['token' => 'NoSuchToken123', 'people_in' => 1]),
    ];

    $response = $this->actingAs($event->owner)->postJson("/door/{$event->id}/sync", ['scans' => $scans])->assertOk();
    $results = collect($response->json('results'))->keyBy('scan_id');

    expect($results[$scans[0]['scan_id']]['status'])->toBe('synced')
        ->and($results[$scans[1]['scan_id']])->toMatchArray(['status' => 'conflict', 'reason' => 'checked_in_elsewhere'])
        ->and($results[$scans[2]['scan_id']])->toMatchArray(['status' => 'conflict', 'reason' => 'needs_confirmation'])
        ->and($results[$scans[3]['scan_id']]['status'])->toBe('invalid')
        ->and(Checkin::query()->where('household_id', $f['Ali']->id)->first()->scanned_at->diffInMinutes(now()))->toBeGreaterThanOrEqual(9.0)
        ->and(Checkin::query()->count())->toBe(2);

    // sending the same queue again records nothing new
    $again = $this->actingAs($event->owner)->postJson("/door/{$event->id}/sync", ['scans' => [$synced]])->assertOk();
    expect($again->json('results.0.status'))->toBe('synced')
        ->and(Checkin::query()->count())->toBe(2);
});

it('counts arrivals per section against the expected people and the hall', function () {
    [$event, $f] = SeatingFixtures::event([['Ali', 3, 0, 'men'], ['Huda', 2, 0, 'women'], ['Omar', 1]], tables: 4, chairs: 4);
    $this->actingAs($event->owner)->postJson("/door/{$event->id}/checkin", p7aScan(['token' => $f['Huda']->token]))->assertOk();

    $stats = $this->actingAs($event->owner)->getJson("/door/{$event->id}/stats")->assertOk()->json('stats');

    expect($stats['sections']['women'])->toBe(['expected' => 2, 'arrived' => 2, 'families' => 1, 'capacity' => 8])
        ->and($stats['sections']['men'])->toBe(['expected' => 3, 'arrived' => 0, 'families' => 0, 'capacity' => 8])
        ->and($stats['total'])->toBe(['expected' => 6, 'arrived' => 2, 'families' => 1, 'capacity' => 16]);
});

it('lists every family for name search', function () {
    [$event] = SeatingFixtures::event([['Ali', 2], ['Silent', 0]]);

    $this->actingAs($event->owner)->getJson("/door/{$event->id}/guests")->assertOk()
        ->assertJsonCount(2, 'guests')
        ->assertJsonPath('guests.0.name', 'Ali')
        ->assertJsonPath('guests.1.status', 'no_reply');
});
