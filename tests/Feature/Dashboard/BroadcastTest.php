<?php

use App\Events\GuestCheckedIn;
use App\Events\HouseholdOpened;
use App\Events\RsvpSubmitted;
use App\Models\Checkin;
use App\Services\OpenTracker;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as Events;
use Tests\Support\DashboardFixtures;

it('broadcasts an open when the tracker records one, once per dedupe window', function () {
    Events::fake([HouseholdOpened::class]);
    [, $h] = DashboardFixtures::event();

    app(OpenTracker::class)->record($h['not_opened'], 'Mozilla/5.0 test');
    app(OpenTracker::class)->record($h['not_opened'], 'Mozilla/5.0 test');

    Events::assertDispatchedTimes(HouseholdOpened::class, 1);
    Events::assertDispatched(HouseholdOpened::class, fn (HouseholdOpened $e) => $e->household->is($h['not_opened']));
});

it('broadcasts an open from a guest visiting their link', function () {
    Events::fake([HouseholdOpened::class]);
    [, $h] = DashboardFixtures::event();

    $this->getJson("/i/{$h['opened']->token}/state?open=1")->assertOk();

    Events::assertDispatched(HouseholdOpened::class);
});

it('broadcasts an RSVP from the guest page', function () {
    Events::fake([RsvpSubmitted::class]);
    [, $h] = DashboardFixtures::event();

    $this->postJson("/i/{$h['opened']->token}/rsvp", ['attending' => true, 'people_count' => 2])->assertOk();

    Events::assertDispatched(RsvpSubmitted::class, fn (RsvpSubmitted $e) => $e->rsvp->household_id === $h['opened']->id);
});

it('queues every dashboard broadcast on the event\'s private channel with the fresh row and numbers', function () {
    [$event, $h] = DashboardFixtures::event();
    $checkin = Checkin::query()->create(['event_id' => $event->id, 'household_id' => $h['attending']->id, 'people_in' => 3, 'scanned_at' => now()]);

    $events = [
        new HouseholdOpened($h['opened']),
        new RsvpSubmitted($h['attending']->rsvp),
        new GuestCheckedIn($checkin),
    ];

    foreach ($events as $broadcast) {
        expect($broadcast)->toBeInstanceOf(ShouldBroadcast::class)
            ->and($broadcast->broadcastOn()[0]->name)->toBe("private-event.{$event->id}");

        $payload = $broadcast->broadcastWith();
        expect($payload['stats']['invited'])->toBe(5)
            ->and($payload['guest']['id'])->toBe($payload['household_id']);
    }

    expect($events[2]->broadcastWith()['stats']['arrived_people'])->toBe(3)
        ->and($events[2]->broadcastWith()['guest']['checked_in_at'])->not->toBeNull()
        ->and($events[1]->broadcastWith()['guest']['status'])->toBe('attending')
        ->and(array_map(fn ($e) => $e->broadcastAs(), $events))->toBe(['household.opened', 'rsvp.submitted', 'guest.checked_in']);
});

it('pushes broadcasts to the queue instead of sending them in the guest\'s request', function () {
    config(['queue.default' => 'database']);
    [, $h] = DashboardFixtures::event();

    app(OpenTracker::class)->record($h['not_opened'], 'ua');

    expect(DB::table('jobs')->where('payload', 'like', '%BroadcastEvent%')->count())->toBe(1);
});

it('never fails the guest when broadcasting breaks', function () {
    Broadcast::shouldReceive('queue')->andThrow(new RuntimeException('Reverb is down'));
    [, $h] = DashboardFixtures::event();

    expect(app(OpenTracker::class)->record($h['not_opened'], 'ua'))->toBeTrue()
        ->and($h['not_opened']->fresh()->open_count)->toBe(1);
});
