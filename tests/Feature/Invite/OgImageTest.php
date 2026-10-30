<?php

use App\Jobs\GenerateOgImage;
use App\Models\Entitlement;
use App\Models\Event;
use App\Models\Household;
use App\Models\OpenLink;
use App\Services\Entitlements;
use App\Services\OgImage\Chromium;
use App\Services\OgImage\OgImage;
use Database\Factories\InvitationFactory;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Stands in for headless Chromium: counts renders and returns a real PNG.
 */
class FakeChromium extends Chromium
{
    public int $renders = 0;

    public array $html = [];

    public bool $fail = false;

    public function available(): bool
    {
        return true;
    }

    public function screenshot(string $html, int $width, int $height, int $timeout): string
    {
        if ($this->fail) {
            throw new RuntimeException('boom');
        }
        $this->renders++;
        $this->html[] = $html;

        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}

beforeEach(function () {
    Storage::fake(config('filesystems.media_disk'));
});

/**
 * Personal preview images are Premium (phase 6B): grant personal links to the event.
 */
function personalPreviews(Event $event): void
{
    Entitlement::query()->create(['event_id' => $event->id, 'feature' => Entitlements::PERSONAL_LINKS]);
}

function fakeChromium(): FakeChromium
{
    $fake = new FakeChromium;
    app()->instance(Chromium::class, $fake);

    return $fake;
}

it('adds og:image and twitter:card tags with the absolute og.png URL', function () {
    [, $household] = invitedHousehold();
    $invitation = $household->event->invitation;
    $hash = app(OgImage::class)->hash($invitation, $invitation->renderConfig(), $household);
    $url = rtrim(config('app.invite_url'), '/')."/i/{$household->token}/og.png?v={$hash}";

    $this->get("/i/{$household->token}")
        ->assertOk()
        ->assertSee('<meta property="og:image" content="'.e($url).'">', false)
        ->assertSee('<meta property="og:image:width" content="1200">', false)
        ->assertSee('<meta property="og:image:height" content="630">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
        ->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=300');
});

it('uses the generic card on the open link page', function () {
    $event = invitedHousehold()[0];
    $link = OpenLink::query()->create(['event_id' => $event->id, 'cap' => 10, 'enabled' => true]);

    $this->get("/i/{$link->token}")->assertSee("/i/{$link->token}/og.png?v=", false);
});

it('does not count link-preview crawlers as opens', function (string $agent) {
    [$event, $household] = invitedHousehold();
    $link = OpenLink::query()->create(['event_id' => $event->id, 'cap' => 10, 'enabled' => true]);

    $this->withHeader('User-Agent', $agent);
    $this->get("/i/{$household->token}")->assertOk();
    $this->getJson("/i/{$household->token}/state?open=1")->assertOk();
    $this->get("/i/{$link->token}")->assertOk();
    $this->getJson("/i/{$link->token}/state?open=1")->assertOk();

    expect($household->fresh()->open_count)->toBe(0)
        ->and($household->linkOpens()->count())->toBe(0)
        ->and($link->fresh()->open_count)->toBe(0);
})->with([
    'WhatsApp' => 'WhatsApp/2.23.20.0 A',
    'Facebook' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
    'Telegram' => 'TelegramBot (like TwitterBot)',
    'Twitter' => 'Twitterbot/1.0',
    'Slack' => 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)',
    'Discord' => 'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)',
]);

it('still counts real browsers', function () {
    [, $household] = invitedHousehold();

    $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) Chrome/130 Mobile')->get("/i/{$household->token}");

    expect($household->fresh()->open_count)->toBe(1);
});

it('renders a missing image on request, caches it and never counts it as an open', function () {
    $chromium = fakeChromium();
    [, $household] = invitedHousehold();
    personalPreviews($household->event);
    $og = app(OgImage::class);
    $invitation = $household->event->invitation;
    $hash = $og->hash($invitation, $invitation->renderConfig(), $household);

    $this->get("/i/{$household->token}/og.png?v={$hash}")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'immutable, max-age=86400, public, s-maxage=31536000');

    $path = "og/{$household->event_id}/{$household->id}-{$hash}.png";
    Storage::disk(config('filesystems.media_disk'))->assertExists($path);

    // cache hit: no second render; an outdated ?v is served but only cached briefly
    $this->get("/i/{$household->token}/og.png?v=0000")
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=300, public, s-maxage=300');

    expect($chromium->renders)->toBe(1)
        ->and($household->fresh()->open_count)->toBe(0)
        ->and($chromium->html[0])->toContain($household->display_name);
});

it('re-renders after a rename and deletes the old image', function () {
    $chromium = fakeChromium();
    [, $household] = invitedHousehold();
    personalPreviews($household->event);
    $disk = Storage::disk(config('filesystems.media_disk'));

    $this->get("/i/{$household->token}/og.png")->assertOk();
    $old = $disk->files("og/{$household->event_id}");

    $household->update(['display_name' => 'عائلة خالد العلي']);
    $this->get("/i/{$household->token}/og.png")->assertOk();
    $new = $disk->files("og/{$household->event_id}");

    expect($chromium->renders)->toBe(2)
        ->and($old)->toHaveCount(1)
        ->and($new)->toHaveCount(1)
        ->and($new[0])->not->toBe($old[0])
        ->and($chromium->html[1])->toContain('لعائلة خالد العلي');
});

it('re-renders when the invitation is edited', function () {
    $chromium = fakeChromium();
    [, $household] = invitedHousehold();

    $this->get("/i/{$household->token}/og.png")->assertOk();
    $this->travel(1)->minutes();
    $household->event->invitation->touch();
    $this->get("/i/{$household->token}/og.png")->assertOk();

    expect($chromium->renders)->toBe(2);
});

it('redirects to the static fallback when the image cannot be rendered', function () {
    fakeChromium()->fail = true;
    [, $household] = invitedHousehold();

    $this->get("/i/{$household->token}/og.png")
        ->assertRedirect(rtrim(config('app.invite_url'), '/').'/og-fallback.png');

    [$width, $height] = getimagesize(public_path('og-fallback.png'));
    expect([$width, $height])->toBe([OgImage::WIDTH, OgImage::HEIGHT]);
});

it('serves the open link card and 404s for unknown or switched-off links', function () {
    $chromium = fakeChromium();
    $event = invitedHousehold()[0];
    $link = OpenLink::query()->create(['event_id' => $event->id, 'cap' => 10, 'enabled' => true]);

    $this->get("/i/{$link->token}/og.png")->assertOk();
    expect($chromium->html[0])->toContain(__('invite.og.open'));
    expect(Storage::disk(config('filesystems.media_disk'))->files("og/{$event->id}"))
        ->toHaveCount(1)->each->toStartWith("og/{$event->id}/open-");

    $link->update(['enabled' => false]);
    $this->get("/i/{$link->token}/og.png")->assertNotFound();
    $this->get('/i/NoSuchToken123/og.png')->assertNotFound();
});

it('writes the recipient line the Arabic way', function (string $name, string $expected) {
    expect(OgImage::recipient($name, 'ar'))->toBe($expected);
})->with([
    ['عائلة خالد العلي', 'لعائلة خالد العلي'],
    ['آل سعود', 'لآل سعود'],
    ['الأستاذ وليد منصور', 'للأستاذ وليد منصور'],
    ['خالد العلي', 'إلى خالد العلي'],
]);

it('renders a real 1200 × 630 PNG with headless Chromium', function () {
    if (! app(Chromium::class)->available()) {
        $this->markTestSkipped('Chromium not installed (set OG_CHROME_PATH).');
    }
    config(['og.chrome_no_sandbox' => true]);

    [, $household] = invitedHousehold();
    $household->update(['display_name' => 'عائلة خالد العلي']);

    $response = $this->get("/i/{$household->token}/og.png")->assertOk()->assertHeader('Content-Type', 'image/png');

    $size = getimagesizefromstring($response->getContent());
    expect($size[0])->toBe(OgImage::WIDTH)
        ->and($size[1])->toBe(OgImage::HEIGHT)
        ->and($size['mime'])->toBe('image/png');
});

it('queues the image when a household is created or renamed on a published invitation', function () {
    [$event] = invitedHousehold();
    Queue::fake();
    config(['og.warm' => true]);

    $household = Household::factory()->for($event)->create();
    Queue::assertPushed(GenerateOgImage::class, fn ($job) => $job->householdId === $household->id && $job->eventId === $event->id);

    $other = Household::factory()->for($event)->create(['display_name' => 'Before']);
    Queue::fake();
    config(['og.warm' => true]);
    $other->update(['seats_allowed' => 4]);
    Queue::assertNothingPushed();
    $other->update(['display_name' => 'After']);
    Queue::assertPushed(GenerateOgImage::class, fn ($job) => $job->householdId === $other->id);
});

it('does not queue images for drafts or open-link replies', function () {
    [$event] = invitedHousehold();
    personalPreviews($event);
    $event->invitation->update(['published_at' => null]);
    Queue::fake();
    config(['og.warm' => true]);

    Household::factory()->for($event)->create();
    Queue::assertNothingPushed();

    $event->invitation->update(['published_at' => now()]);
    Queue::assertPushed(GenerateOgImage::class, 2); // both households, on publish

    Queue::fake();
    config(['og.warm' => true]);
    Household::factory()->for($event)->create(['tags' => [OpenLink::TAG]]);
    Queue::assertNothingPushed();
});

it('queues every card, delayed, when a published invitation is edited', function () {
    [$event, $household] = invitedHousehold();
    personalPreviews($event);
    OpenLink::query()->create(['event_id' => $event->id, 'cap' => 10, 'enabled' => true]);
    Queue::fake();
    config(['og.warm' => true]);

    $config = InvitationFactory::sampleConfig();
    $config['sections'][0]['text'] = 'نص جديد';
    $event->invitation->update(['config' => $config]);

    Queue::assertPushed(GenerateOgImage::class, fn ($job) => $job->householdId === $household->id && $job->delay === config('og.edit_delay'));
    Queue::assertPushed(GenerateOgImage::class, fn ($job) => $job->householdId === null);
});

it('renders in the job, and skips unpublished invitations', function () {
    $chromium = fakeChromium();
    [$event, $household] = invitedHousehold();

    (new GenerateOgImage($event->id, $household->id))->handle(app(OgImage::class));
    expect(Storage::disk(config('filesystems.media_disk'))->files("og/{$event->id}"))->toHaveCount(1);

    $event->invitation->update(['published_at' => null]);
    $household->update(['display_name' => 'Renamed']);
    (new GenerateOgImage($event->id, $household->id))->handle(app(OgImage::class));

    expect($chromium->renders)->toBe(1);
});

// Phase 6B: the personal preview is Premium (plan section 5); Free events share the generic card.

it('gives every guest of a Free event the generic card', function () {
    $chromium = fakeChromium();
    [$event, $household] = invitedHousehold();
    $household->update(['display_name' => 'عائلة خالد العلي']);
    $other = Household::factory()->for($event)->create(['display_name' => 'Khaled Ali']);
    $og = app(OgImage::class);
    $invitation = $event->invitation;

    // the same hash for everyone, and the same as the open link's card
    $hash = $og->hash($invitation, $invitation->renderConfig(), $household);
    expect($og->hash($invitation, $invitation->renderConfig(), $other))->toBe($hash)
        ->and($og->hash($invitation, $invitation->renderConfig()))->toBe($hash);

    $this->get("/i/{$household->token}")->assertOk()->assertSee("/i/{$household->token}/og.png?v={$hash}", false);

    $this->get("/i/{$household->token}/og.png?v={$hash}")->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->get("/i/{$other->token}/og.png?v={$hash}")->assertOk();

    expect($chromium->renders)->toBe(1)
        ->and($chromium->html[0])->not->toContain('خالد العلي')
        ->and($chromium->html[0])->toContain(__('invite.og.open'))
        ->and(Storage::disk(config('filesystems.media_disk'))->files("og/{$event->id}"))
        ->toHaveCount(1)->each->toStartWith("og/{$event->id}/open-");
});

it('draws the guest name once the event has personal links', function () {
    $chromium = fakeChromium();
    [$event, $household] = invitedHousehold();
    $household->update(['display_name' => 'عائلة خالد العلي']);
    $og = app(OgImage::class);
    $generic = $og->hash($event->invitation, $event->invitation->renderConfig(), $household);

    personalPreviews($event);
    $og = app(OgImage::class);
    $personal = $og->hash($event->invitation, $event->invitation->renderConfig(), $household);

    $this->get("/i/{$household->token}/og.png")->assertOk();

    expect($personal)->not->toBe($generic)
        ->and($chromium->html[0])->toContain('لعائلة خالد العلي')
        ->and(Storage::disk(config('filesystems.media_disk'))->files("og/{$event->id}"))
        ->each->toStartWith("og/{$event->id}/{$household->id}-");
});

it('queues one generic card for a Free event instead of one per guest', function () {
    [$event] = invitedHousehold();
    Household::factory()->for($event)->count(3)->create();
    Queue::fake();

    GenerateOgImage::warmEvent($event);

    Queue::assertPushed(GenerateOgImage::class, 1);
});
