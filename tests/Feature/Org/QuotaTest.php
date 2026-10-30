<?php

use App\Models\Event;
use App\Models\Household;
use App\Models\Plan;
use App\Services\Entitlements;
use App\Services\Org\OrgQuota;
use App\Services\Org\OrgSubscriptions;
use App\Services\PublishGate;
use Illuminate\Support\Facades\Notification;
use Tests\Support\OrgFixtures;

beforeEach(function () {
    OrgFixtures::setUp();
    Notification::fake();
});

function publishOrgEvent(Event $event, $user)
{
    Household::factory()->for($event)->seats(2)->create();

    return test()->actingAs($user)->post("/create/{$event->id}/publish");
}

it('gives the org plan\'s features to its events while the subscription is live', function () {
    [$org, $owner] = OrgFixtures::org();
    $event = OrgFixtures::event($org, $owner);
    $entitlements = app(Entitlements::class);

    expect($entitlements->hasFeature($event, Entitlements::SEATING))->toBeFalse()
        ->and(app(PublishGate::class)->check($event)['allowed'])->toBeFalse();

    OrgFixtures::subscribe($org, 'venue_starter');
    $event->refresh();
    expect($entitlements->hasFeature($event, Entitlements::SEATING))->toBeTrue()
        ->and($entitlements->hasFeature($event, Entitlements::QR_CHECKIN))->toBeTrue()
        // a Signature design publishes without paying for the event
        ->and(app(PublishGate::class)->check($event)['allowed'])->toBeTrue();
});

it('counts the signature events published this month and refuses at the limit with a clear message', function () {
    [$org, $owner] = OrgFixtures::org();
    $staff = OrgFixtures::member($org);
    OrgFixtures::subscribe($org, 'venue_starter');
    Plan::query()->whereKey('venue_starter')->first()->forceFill(['limits' => [...Plan::query()->find('venue_starter')->limits, 'signature_events_per_month' => 2]])->save();

    foreach (range(1, 2) as $i) {
        publishOrgEvent(OrgFixtures::event($org, $staff), $staff)->assertSessionHasNoErrors();
    }
    expect(Event::query()->whereNotNull('org_quota_at')->count())->toBe(2)
        ->and(Event::query()->whereNotNull('org_quota_at')->pluck('plan_id')->unique()->all())->toBe(['signature'])
        ->and(app(OrgQuota::class)->summary($org->fresh()))->toMatchArray(['limit' => 2, 'used' => 2, 'remaining' => 0]);

    $third = OrgFixtures::event($org, $staff);
    publishOrgEvent($third, $staff)->assertSessionHasErrors('publish');
    expect($third->invitation->fresh()->published_at)->toBeNull()
        ->and(session('errors')->first('publish'))->toContain('٢');

    // creating another client event is refused too
    $this->actingAs($staff)->post("/org/{$org->id}/events", ['occasion' => 'wedding'])->assertSessionHasErrors('quota');

    // re-publishing an already published event uses no new slot
    $published = Event::query()->whereNotNull('org_quota_at')->first();
    $this->actingAs($staff)->post("/create/{$published->id}/publish")->assertSessionHasNoErrors();
    expect(app(OrgQuota::class)->summary($org->fresh())['used'])->toBe(2);
});

it('starts a new count in the next month of the subscription', function () {
    [$org, $owner] = OrgFixtures::org();
    $subscription = OrgFixtures::subscribe($org, 'venue_starter_yearly');
    Plan::query()->whereKey('venue_starter_yearly')->first()->forceFill(['limits' => [...Plan::query()->find('venue_starter_yearly')->limits, 'signature_events_per_month' => 1]])->save();

    publishOrgEvent(OrgFixtures::event($org, $owner), $owner)->assertSessionHasNoErrors();
    publishOrgEvent(OrgFixtures::event($org, $owner), $owner)->assertSessionHasErrors('publish');

    $this->travelTo($subscription->current_period_start->addMonthNoOverflow()->addHour());
    publishOrgEvent(OrgFixtures::event($org, $owner), $owner)->assertSessionHasNoErrors();
    expect(Event::query()->whereNotNull('org_quota_at')->count())->toBe(2);
});

it('uses no slot for an event that bought Signature itself', function () {
    [$org, $owner] = OrgFixtures::org();
    OrgFixtures::subscribe($org);
    $event = OrgFixtures::event($org, $owner, ['plan_id' => 'signature']);

    publishOrgEvent($event, $owner)->assertSessionHasNoErrors();
    expect($event->fresh()->org_quota_at)->toBeNull();
});

it('lets events keep what they had after the subscription expires, with no new signature events', function () {
    [$org, $owner] = OrgFixtures::org(['white_label' => ['brand_color' => '#112233']]);
    $subscription = OrgFixtures::subscribe($org);
    $published = OrgFixtures::event($org, $owner);
    publishOrgEvent($published, $owner)->assertSessionHasNoErrors();
    $draft = OrgFixtures::event($org, $owner);

    app(OrgSubscriptions::class)->end($subscription, 'expired', 'test');

    $entitlements = app(Entitlements::class);
    expect($org->fresh()->plan_id)->toBeNull()
        ->and($entitlements->hasFeature($published->fresh(), Entitlements::SEATING))->toBeTrue()
        ->and($published->fresh()->plan_id)->toBe('signature')
        ->and($entitlements->hasFeature($draft->fresh(), Entitlements::SEATING))->toBeFalse()
        // the draft now needs its own payment
        ->and(app(PublishGate::class)->check($draft->fresh())['allowed'])->toBeFalse();
});
