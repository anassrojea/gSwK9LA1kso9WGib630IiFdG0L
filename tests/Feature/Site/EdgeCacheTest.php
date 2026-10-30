<?php

use App\Jobs\PurgeEdgeCache;
use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\OpenLink;
use App\Models\Plan;
use App\Services\EdgeCache\CloudflarePurge;
use Database\Factories\InvitationFactory;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'app.url' => 'https://daawa.app',
        'app.invite_url' => 'https://daawa.link',
        'services.cloudflare.zone_id' => 'zone123',
        'services.cloudflare.api_token' => 'cf-token',
    ]);
});

function draftEvent(): Event
{
    $event = Event::factory()->create();
    Invitation::factory()->for($event)->create(['config' => InvitationFactory::sampleConfig(), 'published_at' => null]);

    return $event->load('invitation');
}

it('queues a purge when a published invitation is edited, published or unpublished', function () {
    Queue::fake();
    [$event] = invitedHousehold();
    $invitation = $event->invitation()->first();

    $config = $invitation->config;
    $config['locale'] = 'en';
    $invitation->update(['config' => $config]);
    Queue::assertPushed(PurgeEdgeCache::class, fn (PurgeEdgeCache $job) => $job->eventId === $event->id && $job->tokens === null);

    // debounced: more edits while the purge waits don't queue another one
    $config['locale'] = 'ar';
    $invitation->update(['config' => $config]);
    Queue::assertPushed(PurgeEdgeCache::class, 1);

    // once it has started, the next change queues the next purge
    releasePurgeLock(new PurgeEdgeCache($event->id));
    $invitation->update(['published_at' => null]);
    Queue::assertPushed(PurgeEdgeCache::class, 2);

    releasePurgeLock(new PurgeEdgeCache($event->id));
    $invitation->update(['published_at' => now()]);
    Queue::assertPushed(PurgeEdgeCache::class, 3);
});

function releasePurgeLock(PurgeEdgeCache $job): void
{
    Cache::lock(UniqueLock::getKey($job))->forceRelease();
}

it('does not purge drafts', function () {
    Queue::fake();
    $event = draftEvent();
    $invitation = $event->invitation;

    $config = $invitation->config;
    $config['locale'] = 'en';
    $invitation->update(['config' => $config]);
    Household::factory()->for($event)->create()->update(['display_name' => 'Renamed']);

    Queue::assertNotPushed(PurgeEdgeCache::class);
});

it('purges a household renamed or deleted, the open link, and the event when its plan changes', function () {
    Queue::fake();
    [$event, $household] = invitedHousehold();

    $household->update(['seats_allowed' => 4]);
    Queue::assertNotPushed(PurgeEdgeCache::class);

    $household->update(['display_name' => 'عائلة جديدة']);
    Queue::assertPushed(PurgeEdgeCache::class, fn ($job) => $job->tokens === [$household->token]);

    $other = Household::factory()->for($event)->create();
    $other->delete();
    Queue::assertPushed(PurgeEdgeCache::class, fn ($job) => $job->tokens === [$other->token]);

    $link = OpenLink::query()->create(['event_id' => $event->id, 'enabled' => false, 'cap' => 100]);
    $link->update(['enabled' => true]);
    Queue::assertPushed(PurgeEdgeCache::class, fn ($job) => $job->tokens === [$link->token]);

    $plan = Plan::factory()->create(['id' => 'premium_test']);
    $event->update(['plan_id' => $plan->id]);
    Queue::assertPushed(PurgeEdgeCache::class, fn ($job) => $job->tokens === null && $job->eventId === $event->id);
});

it('purges when the event ends or is archived and when its media is deleted (storage expiry)', function () {
    Queue::fake();
    [$event] = invitedHousehold();

    $event->update(['status' => 'archived']);
    Queue::assertPushed(PurgeEdgeCache::class, fn ($job) => $job->eventId === $event->id && $job->tokens === null);

    releasePurgeLock(new PurgeEdgeCache($event->id));
    $media = Media::factory()->create(['event_id' => $event->id]);
    Queue::assertPushed(PurgeEdgeCache::class, 1);
    $media->delete();
    Queue::assertPushed(PurgeEdgeCache::class, 2);
});

it('is unique per event until it runs, so a burst of edits purges once', function () {
    [$event] = invitedHousehold();
    $job = new PurgeEdgeCache($event->id);

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe($event->id)
        ->and($job->uniqueFor)->toBe(60)
        ->and((new PurgeEdgeCache($event->id, ['abcdefghij12']))->uniqueId())->not->toBe($event->id)
        ->and($job->tries)->toBeGreaterThan(1)
        ->and($job->backoff())->toBe([10, 30, 90, 300]);
});

it('purges every link of the event on both domains, 30 URLs per API call', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'errors' => [], 'result' => ['id' => 'x']])]);
    [$event, $first] = invitedHousehold();
    Household::factory()->for($event)->count(9)->create();
    $open = OpenLink::query()->create(['event_id' => $event->id, 'enabled' => true, 'cap' => 50]);
    // a household made by an open-link reply has no page of its own
    Household::factory()->for($event)->create(['tags' => [OpenLink::TAG]]);

    (new PurgeEdgeCache($event->id))->handle(app(CloudflarePurge::class));

    // 11 links × 2 URLs × 2 domains = 44 → 2 calls (30 + 14)
    Http::assertSentCount(2);
    $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
    expect($sent[0]->url())->toBe('https://api.cloudflare.com/client/v4/zones/zone123/purge_cache')
        ->and($sent[0]->hasHeader('Authorization', 'Bearer cf-token'))->toBeTrue()
        ->and($sent[0]['files'])->toHaveCount(30)
        ->and($sent[1]['files'])->toHaveCount(14);

    $all = $sent->flatMap(fn (Request $r) => $r['files'])->all();
    expect($all)->toContain("https://daawa.app/i/{$first->token}", "https://daawa.link/i/{$first->token}/og.png", "https://daawa.link/i/{$open->token}");
});

it('purges only the given links for a renamed or deleted household', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);
    [$event] = invitedHousehold();

    (new PurgeEdgeCache($event->id, ['GoneToken123']))->handle(app(CloudflarePurge::class));

    Http::assertSent(fn (Request $r) => $r['files'] === [
        'https://daawa.app/i/GoneToken123', 'https://daawa.app/i/GoneToken123/og.png',
        'https://daawa.link/i/GoneToken123', 'https://daawa.link/i/GoneToken123/og.png',
    ]);
});

it('throws on an API error so the queue retries', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['code' => 10000]]], 403)]);
    [$event] = invitedHousehold();

    expect(fn () => (new PurgeEdgeCache($event->id))->handle(app(CloudflarePurge::class)))
        ->toThrow(RequestException::class);
});

it('does nothing but log without Cloudflare keys', function () {
    config(['services.cloudflare.zone_id' => null, 'services.cloudflare.api_token' => null]);
    Http::fake();
    Log::spy();
    [$event] = invitedHousehold();

    (new PurgeEdgeCache($event->id))->handle(app(CloudflarePurge::class));

    Http::assertNothingSent();
    Log::shouldHaveReceived('info')->withArgs(fn ($message) => str_contains($message, 'Edge cache purge skipped'));
});

it('runs from an edit end to end with the sync queue', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);
    [$event, $household] = invitedHousehold();

    $household->update(['display_name' => 'Renamed family']);

    Http::assertSent(fn (Request $r) => in_array("https://daawa.link/i/{$household->token}", $r['files'], true));
});
