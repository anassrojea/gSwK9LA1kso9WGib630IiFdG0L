<?php

use App\Models\OrgMember;
use App\Models\SeatAssignment;
use App\Services\Album\Album;
use App\Services\EventDay;
use Carbon\CarbonImmutable;
use Tests\Support\DashboardFixtures;
use Tests\Support\SeatingFixtures;

/** An event starting at 20:00 Riyadh time on 2026-12-10 (17:00 UTC). */
function p7aLiveEvent(string $plan = 'signature', array $families = [['Ali', 2]]): array
{
    return SeatingFixtures::event($families, plan: $plan, attributes: [
        'starts_at' => CarbonImmutable::parse('2026-12-10 20:00', 'Asia/Riyadh')->utc(),
        'timezone' => 'Asia/Riyadh',
        'live_stream_url' => 'https://youtube.com/live/abc',
    ]);
}

it('shows the live day panel only within the event-day window, in the event\'s time zone', function () {
    [, $f] = p7aLiveEvent();
    $state = fn () => $this->getJson("/i/{$f['Ali']->token}/state")->assertOk()->json('live');

    $this->travelTo(CarbonImmutable::parse('2026-12-09 23:30', 'Asia/Riyadh'));
    expect($state())->toBeNull();

    // 12 hours before the start, but never before midnight of the day itself
    $this->travelTo(CarbonImmutable::parse('2026-12-10 07:59', 'Asia/Riyadh'));
    expect($state())->toBeNull();
    $this->travelTo(CarbonImmutable::parse('2026-12-10 08:00', 'Asia/Riyadh'));
    expect($state())->not->toBeNull();

    // …until 8 hours after the start, past midnight
    $this->travelTo(CarbonImmutable::parse('2026-12-11 03:59', 'Asia/Riyadh'));
    $live = $state();
    expect($live['stream'])->toBe('https://youtube.com/live/abc')
        ->and($live['directions'])->toStartWith('https://')
        // Signature includes the photo wall: guests get its public link
        ->and($live['wall'])->toEndWith('/wall');

    $this->travelTo(CarbonImmutable::parse('2026-12-11 04:01', 'Asia/Riyadh'));
    expect($state())->toBeNull();
});

it('starts the window at midnight for a morning event', function () {
    [$event] = p7aLiveEvent();
    $event->update(['starts_at' => CarbonImmutable::parse('2026-12-10 10:00', 'Asia/Riyadh')->utc()]);

    [$from, $until] = EventDay::window($event->fresh());
    expect($from->format('Y-m-d H:i'))->toBe('2026-12-10 00:00')
        ->and($until->format('Y-m-d H:i'))->toBe('2026-12-10 18:00');
});

it('needs the live day feature', function () {
    [, $f] = p7aLiveEvent(plan: 'premium');
    $this->travelTo(CarbonImmutable::parse('2026-12-10 19:00', 'Asia/Riyadh'));

    expect($this->getJson("/i/{$f['Ali']->token}/state")->json('live'))->toBeNull();
});

it('keeps the live panel out of the cached page HTML and fills it from /state', function () {
    [$event, $f] = p7aLiveEvent();
    $event->invitation->update(['published_at' => now()]);
    SeatAssignment::query()->create(['event_id' => $event->id, 'household_id' => $f['Ali']->id, 'table_id' => 'T2', 'seat_index' => 0]);
    $this->travelTo(CarbonImmutable::parse('2026-12-10 19:00', 'Asia/Riyadh'));

    $html = $this->get("/i/{$f['Ali']->token}")->assertOk()->getContent();
    expect($html)->toContain('data-live hidden')->not->toContain('youtube.com/live/abc');

    $state = $this->getJson("/i/{$f['Ali']->token}/state")->json();
    expect($state['live']['stream'])->toBe('https://youtube.com/live/abc')
        ->and($state['seat']['number'])->toBe('2')
        ->and($state['qr'])->not->toBeNull();
});

it('lets the host set an https live stream link and nothing else', function () {
    [$event] = p7aLiveEvent();
    $put = fn ($url) => $this->actingAs($event->owner)->putJson("/dashboard/events/{$event->id}/live-stream", ['url' => $url]);

    $put('https://www.instagram.com/live/xyz')->assertOk()->assertJsonPath('live_stream_url', 'https://www.instagram.com/live/xyz');
    $put('http://example.com/live')->assertUnprocessable()->assertJsonValidationErrors('url');
    $put('javascript:alert(1)')->assertUnprocessable();
    $put('https://user:pass@example.com/')->assertUnprocessable();
    $put('https://'.str_repeat('a', 600).'.com')->assertUnprocessable();
    $put('not a url')->assertUnprocessable();
    expect($event->fresh()->live_stream_url)->toBe('https://www.instagram.com/live/xyz');

    $put(null)->assertOk();
    expect($event->fresh()->live_stream_url)->toBeNull();

    $this->actingAs(DashboardFixtures::member($event, OrgMember::ROLE_DOOR))
        ->putJson("/dashboard/events/{$event->id}/live-stream", ['url' => 'https://x.com'])->assertForbidden();
});

it('gives the dashboard panel its links, features and window', function () {
    [$event] = p7aLiveEvent();

    $this->actingAs($event->owner)->getJson("/dashboard/events/{$event->id}/day")->assertOk()
        ->assertJsonPath('features.seating', true)
        ->assertJsonPath('features.qr_checkin', true)
        ->assertJsonPath('urls.door', "/door/{$event->id}")
        ->assertJsonPath('urls.seating', "/dashboard/events/{$event->id}/seating")
        ->assertJsonPath('live_stream_url', 'https://youtube.com/live/abc')
        ->assertJsonPath('live.from', '2026-12-10T08:00:00+03:00');
});

it('never gives guests the link to a private photo wall', function () {
    [$event, $f] = p7aLiveEvent();
    app(Album::class)->update($event, ['wall_private' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-12-10 21:00', 'Asia/Riyadh'));

    $live = $this->getJson("/i/{$f['Ali']->token}/state")->assertOk()->json('live');

    expect($live)->not->toBeNull()
        ->and($live['wall'])->toBeNull();
});
