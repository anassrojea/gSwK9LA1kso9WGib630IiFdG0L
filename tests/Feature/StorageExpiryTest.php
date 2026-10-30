<?php

use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\Event;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\OpenLink;
use App\Models\Order;
use App\Models\Rsvp;
use App\Models\User;
use App\Notifications\StorageExpiring;
use App\Services\InvitationConfig\MediaUrl;
use App\Services\Payments\OrderFulfillment;
use App\Services\Storage\StoragePeriod;
use Carbon\CarbonImmutable;
use Database\Factories\InvitationFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Payments;

/*
| Phase 6B: the storage period (plan section 11): set on publish and payment,
| warnings 14 and 3 days ahead, media purge after it, archive after the grace period.
*/

beforeEach(function () {
    Payments::setUp();
    Notification::fake();
    Storage::fake(config('filesystems.media_disk'));
    $this->disk = Storage::disk(config('filesystems.media_disk'));
});

/**
 * A published event whose storage ends on $until, with an uploaded cover, a guest photo,
 * a link preview image and one family that replied.
 *
 * @return array{0: Event, 1: Media, 2: Household}
 */
function eventWithMedia(CarbonImmutable $until): array
{
    $event = Event::factory()->create(['storage_until' => $until->toDateString(), 'plan_id' => 'premium']);
    $cover = Media::factory()->create(['event_id' => $event->id, 'storage_key' => "events/{$event->id}/cover.webp", 'thumb_key' => "events/{$event->id}/cover-thumb.webp"]);
    Media::factory()->create(['event_id' => $event->id, 'storage_key' => "events/{$event->id}/guest-1.jpg"]);

    $config = InvitationFactory::sampleConfig();
    $config['media'] = ['cover' => ['type' => 'image', 'src' => "media:{$cover->id}"]];
    Invitation::factory()->for($event)->create(['config' => $config, 'published_at' => now()]);

    foreach (["events/{$event->id}/cover.webp", "events/{$event->id}/cover-thumb.webp", "events/{$event->id}/guest-1.jpg", "og/{$event->id}/open-abc.png"] as $file) {
        test()->disk->put($file, 'x');
    }

    $household = Household::factory()->for($event)->create();
    Rsvp::factory()->create(['household_id' => $household->id, 'attending' => true, 'people_count' => 3]);

    return [$event->fresh(), $cover, $household];
}

function expireStorage(CarbonImmutable $today, bool $dry = false)
{
    return test()->artisan('events:expire-storage', array_filter(['--date' => $today->toDateString(), '--dry-run' => $dry]));
}

// ---- the period ---------------------------------------------------------------

it('sets storage_until to the event date plus the plan days when an event is published', function () {
    $owner = User::factory()->create();
    $event = Payments::event($owner, ['starts_at' => CarbonImmutable::parse('2026-12-10 17:00', 'UTC'), 'timezone' => 'Asia/Riyadh']);
    $event->invitation->update(['config' => Payments::freeConfig()]);
    OpenLink::query()->create(['event_id' => $event->id, 'cap' => 50, 'enabled' => true]);

    $this->actingAs($owner)->post("/create/{$event->id}/publish")->assertSessionHasNoErrors();

    // Free: storage_days 0, so the event's own date
    expect($event->fresh()->storage_until->toDateString())->toBe('2026-12-10');
});

it('extends storage to the paid plan and never shortens a later date', function () {
    // 23:30 in Riyadh on 10 December
    $event = Payments::event(null, ['starts_at' => CarbonImmutable::parse('2026-12-10 20:30', 'UTC'), 'timezone' => 'Asia/Riyadh']);
    $admin = User::factory()->admin()->create();

    $order = Order::factory()->create(['user_id' => $event->owner_id, 'event_id' => $event->id, 'currency' => 'SAR', 'total_cents' => 10900,
        'items' => [['type' => 'plan', 'id' => 'premium', 'name' => 'Premium', 'quantity' => 1, 'unit_amount' => 10900, 'amount' => 10900]]]);
    app(OrderFulfillment::class)->approveTransfer($order, $admin, 'BANK-1');

    // Premium: 30 days after the event's date in its own time zone
    expect($event->fresh()->storage_until->toDateString())->toBe('2027-01-09');

    // the admin extended it further: a later payment keeps the later date
    $event->update(['storage_until' => '2027-06-01']);
    $fresh = $event->fresh();
    expect(app(StoragePeriod::class)->apply($fresh))->toBeFalse()
        ->and($fresh->storage_until->toDateString())->toBe('2027-06-01');
});

// ---- warnings -----------------------------------------------------------------

it('warns the host 14 and 3 days before, once each', function () {
    $until = CarbonImmutable::parse('2026-12-20');
    [$event] = eventWithMedia($until);

    expireStorage($until->subDays(15))->assertSuccessful();
    Notification::assertNothingSent();

    expireStorage($until->subDays(14))->expectsOutputToContain('1 warning(s)');
    expireStorage($until->subDays(14))->expectsOutputToContain('0 warning(s)');
    expireStorage($until->subDays(10));
    Notification::assertSentToTimes($event->owner, StorageExpiring::class, 1);

    expireStorage($until->subDays(3));
    expireStorage($until->subDays(2));
    expireStorage($until);
    Notification::assertSentToTimes($event->owner, StorageExpiring::class, 2);

    expect($event->fresh()->storage_warnings)->toMatchArray(['until' => '2026-12-20'])
        ->and(array_keys($event->fresh()->storage_warnings['sent']))->toEqualCanonicalizing(['14', '3'])
        ->and(AuditLog::query()->where('action', 'event.storage_warning')->count())->toBe(2);
});

it('sends only the 3-day warning when the run catches up late, and warns again after an extension', function () {
    $until = CarbonImmutable::parse('2026-12-20');
    [$event] = eventWithMedia($until);

    expireStorage($until->subDays(2));
    expireStorage($until->subDays(1));
    Notification::assertSentToTimes($event->owner, StorageExpiring::class, 1);
    Notification::assertSentTo($event->owner, StorageExpiring::class, fn (StorageExpiring $n) => $n->days === 2);

    // extended: the new date gets its own warnings
    $event->update(['storage_until' => '2027-03-20']);
    expireStorage(CarbonImmutable::parse('2027-03-06'));
    Notification::assertSentToTimes($event->owner, StorageExpiring::class, 2);
});

it('does not warn when there is nothing to delete', function () {
    $event = Event::factory()->create(['storage_until' => '2026-12-20']);

    expireStorage(CarbonImmutable::parse('2026-12-17'));

    Notification::assertNothingSent();
    expect($event->fresh()->storage_warnings)->toBeNull();
});

it('links the warning email to the photo cloud add-on on the upgrade page', function () {
    [$event] = eventWithMedia(CarbonImmutable::parse('2026-12-20'));
    $event->owner->update(['locale' => 'en']);

    $mail = (new StorageExpiring($event, 3))->toMail($event->owner);

    expect($mail->actionUrl)->toBe(route('dashboard.events.upgrade', $event).'?addon=photo_cloud_90d#addons')
        ->and($mail->subject)->toContain($event->title);

    $this->actingAs($event->owner)->get(route('dashboard.events.upgrade', ['event' => $event, 'addon' => 'photo_cloud_90d']))
        ->assertInertia(fn ($page) => $page->where('checkout.preselect_addon', 'photo_cloud_90d'));
    $this->get(route('dashboard.events.upgrade', ['event' => $event, 'addon' => 'nope']))
        ->assertInertia(fn ($page) => $page->where('checkout.preselect_addon', null));
});

// ---- purge and archive --------------------------------------------------------

it('deletes the media after the storage date but keeps the replies and the text', function () {
    $until = CarbonImmutable::parse('2026-12-20');
    [$event, $cover, $household] = eventWithMedia($until);
    $text = $event->invitation->config['sections'];

    expireStorage($until)->expectsOutputToContain('0 purge(s)');
    expect(Media::query()->where('event_id', $event->id)->count())->toBe(2);

    expireStorage($until->addDay())->expectsOutputToContain('1 purge(s)');

    $event->refresh();
    expect($event->status)->toBe('ended')
        ->and($event->media_purged_at)->not->toBeNull()
        ->and(Media::query()->where('event_id', $event->id)->count())->toBe(0)
        ->and($this->disk->allFiles("events/{$event->id}"))->toBe([])
        ->and($this->disk->allFiles("og/{$event->id}"))->toBe([])
        // the guests' replies and the invitation's text stay
        ->and($household->fresh()->rsvp->people_count)->toBe(3)
        ->and($event->invitation->config['sections'])->toBe($text)
        // the cover reference resolves to nothing now
        ->and((new MediaUrl($event))("media:{$cover->id}"))->toBeNull();

    $log = AuditLog::query()->where('action', 'event.media_purged')->sole();
    expect($log->data)->toMatchArray(['files' => 4, 'media' => 2, 'until' => '2026-12-20']);

    // the invitation still opens until it is archived
    $this->get("/i/{$household->token}")->assertOk();

    // purged once
    expireStorage($until->addDays(2))->expectsOutputToContain('0 purge(s)');
});

it('archives the event after the grace period, so its links are a 404', function () {
    $until = CarbonImmutable::parse('2026-12-20');
    [$event, , $household] = eventWithMedia($until);

    expireStorage($until->addDays(30))->expectsOutputToContain('0 archive(s)');
    expect($event->fresh()->status)->toBe('ended');

    expireStorage($until->addDays(31))->expectsOutputToContain('1 archive(s)');
    expect($event->fresh()->status)->toBe('archived')
        ->and(Rsvp::query()->where('household_id', $household->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'event.archived')->where('entity_id', $event->id)->exists())->toBeTrue();

    $this->get("/i/{$household->token}")->assertNotFound();

    config(['retention.archive_grace_days' => 5]);
    [$other] = eventWithMedia($until);
    expireStorage($until->addDays(6));
    expect($other->fresh()->status)->toBe('archived');
});

it('changes nothing in a dry run', function () {
    $until = CarbonImmutable::parse('2026-12-20');
    [$warned] = eventWithMedia($until->addDays(14));
    [$expired] = eventWithMedia($until->subDays(40));

    expireStorage($until, dry: true)
        ->expectsOutputToContain("[dry run] warning (14 days): event {$warned->id}")
        ->expectsOutputToContain("[dry run] purge: event {$expired->id} (4 files, 2 media rows)")
        ->expectsOutputToContain("[dry run] archive: event {$expired->id}")
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect($warned->fresh()->storage_warnings)->toBeNull()
        ->and($expired->fresh()->status)->toBe('active')
        ->and($expired->fresh()->media_purged_at)->toBeNull()
        ->and(Media::query()->count())->toBe(4)
        ->and($this->disk->allFiles("events/{$expired->id}"))->toHaveCount(3)
        ->and(AuditLog::query()->whereIn('action', ['event.storage_warning', 'event.media_purged', 'event.archived'])->count())->toBe(0);
});

it('keeps everything for a photo cloud bought forever', function () {
    $until = CarbonImmutable::parse('2026-12-20');
    [$event] = eventWithMedia($until);
    Entitlement::query()->create(['event_id' => $event->id, 'feature' => 'photo_cloud', 'until' => null]);

    expireStorage($until->addDays(60));

    expect($event->fresh()->status)->toBe('active')
        ->and(Media::query()->where('event_id', $event->id)->count())->toBe(2);
    Notification::assertNothingSent();
});

it('is scheduled daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'events:expire-storage'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('23 4 * * *');
});
