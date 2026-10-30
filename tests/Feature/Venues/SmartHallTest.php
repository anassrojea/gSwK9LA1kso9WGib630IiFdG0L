<?php

use App\Http\Controllers\Marketing\VenueDirectoryController;
use App\Models\Event;
use App\Models\Household;
use App\Models\Organization;
use App\Models\OrgMember;
use App\Models\SeatAssignment;
use App\Models\SiteCounter;
use App\Models\User;
use App\Models\VenueProfile;
use App\Services\Pdf\PdfRenderer;
use App\Services\Venues\FindTable;
use Carbon\CarbonImmutable;
use Tests\Support\SeatingFixtures;
use Tests\Support\VenueFixtures;

/*
| Venue QR and "Smart Hall" (phase 8B, plan section 9 items 2 and 9): the
| reception poster's /v/{code}, the posters, and "find your table".
*/

beforeEach(function () {
    config(['app.url' => 'https://daawa.app', 'app.invite_url' => 'https://daawa.link', 'venues.find_per_minute' => 50]);
    VenueFixtures::plans();
});

function p8bFakePdf(): void
{
    test()->mock(PdfRenderer::class, function ($mock) {
        $mock->shouldReceive('available')->andReturn(true);
        $mock->shouldReceive('render')->andReturnUsing(fn (string $html) => '%PDF-fake '.$html);
    });
}

it('counts poster scans anonymously and opens the venue page with UTM parameters', function () {
    $org = Organization::factory()->venue()->create(['plan_id' => 'venue_pro']);
    $venue = VenueProfile::factory()->approved()->create(['org_id' => $org->id, 'slug' => 'al-noor-hall']);

    $this->get("/v/{$venue->qr_code}", ['User-Agent' => 'Mozilla/5.0 (iPhone)'])
        ->assertRedirect('https://daawa.app/venues/al-noor-hall?utm_source=venue_qr&utm_medium=poster&utm_campaign=venue')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertCookieMissing(config('session.cookie'));
    $this->get("/v/{$venue->qr_code}", ['User-Agent' => 'Mozilla/5.0 (Android)']);
    // link-preview bots are not people scanning the poster
    $this->get("/v/{$venue->qr_code}", ['User-Agent' => 'WhatsApp/2.23']);

    expect(SiteCounter::total(VenueDirectoryController::SCAN_COUNTER, $venue->id))->toBe(2)
        ->and(SiteCounter::query()->first()->getAttributes())->not->toHaveKeys(['ip', 'user_agent']);

    // a venue that is not (or no longer) listed sends scans to the landing page, still counted
    $venue->update(['status' => VenueProfile::STATUS_HIDDEN]);
    $this->get("/v/{$venue->qr_code}", ['User-Agent' => 'Mozilla/5.0'])->assertRedirect('https://daawa.app/?utm_source=venue_qr&utm_medium=poster&utm_campaign=venue');
    $this->get('/v/NOPENOPE')->assertRedirect();
    $this->get('/v/short')->assertNotFound();
});

it('prints the bilingual venue poster in A4 and A5 for the venue team', function () {
    p8bFakePdf();
    $org = Organization::factory()->venue()->create(['plan_id' => 'venue_pro', 'name' => 'قاعة النور']);
    $owner = User::factory()->create();
    OrgMember::factory()->create(['org_id' => $org->id, 'user_id' => $owner->id, 'role' => OrgMember::ROLE_OWNER]);
    $venue = VenueProfile::factory()->create(['org_id' => $org->id, 'name_en' => 'Al Noor Hall']);

    $a4 = $this->actingAs($owner)->get("/dashboard/venue/poster.pdf?org={$org->id}&size=A4")->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
    expect($a4)->toContain('احجز دعوتك الرقمية معنا')->toContain('Book your invitation with us')
        ->toContain('قاعة النور')->toContain('Al Noor Hall')
        ->toContain('daawa.app/v/'.$venue->qr_code)->toContain('<svg')->toContain('size: A4 portrait');

    $a5 = $this->actingAs($owner)->get("/dashboard/venue/poster.pdf?org={$org->id}&size=A5")->assertOk()->getContent();
    expect($a5)->toContain('size: A5 portrait');

    $this->actingAs(User::factory()->create())->get("/dashboard/venue/poster.pdf?org={$org->id}")->assertForbidden();
});

it('prints the posters as real PDFs when Chromium is there', function () {
    if (! app(PdfRenderer::class)->available()) {
        $this->markTestSkipped('Chromium is not installed (OG_CHROME_PATH).');
    }
    $org = Organization::factory()->venue()->create(['plan_id' => 'venue_pro']);
    $owner = User::factory()->create();
    OrgMember::factory()->create(['org_id' => $org->id, 'user_id' => $owner->id, 'role' => OrgMember::ROLE_OWNER]);
    VenueProfile::factory()->create(['org_id' => $org->id]);
    [$event] = SeatingFixtures::event([['Ali', 2]]);
    $event->invitation->update(['published_at' => now(), 'slug' => 'lina-omar']);

    expect($this->actingAs($owner)->get("/dashboard/venue/poster.pdf?org={$org->id}")->assertOk()->getContent())->toStartWith('%PDF')
        ->and($this->actingAs($event->owner)->get("/events/{$event->id}/find-table.pdf?size=A5")->assertOk()->getContent())->toStartWith('%PDF');
});

it('prints the find-your-table QR of a published event with the seating feature', function () {
    p8bFakePdf();
    [$event] = SeatingFixtures::event([['Ali', 2]]);
    $event->invitation->update(['published_at' => now(), 'slug' => 'lina-omar']);

    $html = $this->actingAs($event->owner)->get("/events/{$event->id}/find-table.pdf")->assertOk()->getContent();
    expect($html)->toContain('اعرف طاولتك')->toContain('Find your table')->toContain('daawa.link/e/lina-omar/find')->toContain('<svg');

    [$premium] = SeatingFixtures::event([['Ali', 2]], plan: 'premium');
    $premium->invitation->update(['published_at' => now()]);
    $this->actingAs($premium->owner)->get("/events/{$premium->id}/find-table.pdf")->assertForbidden();
    $this->actingAs(User::factory()->create())->get("/events/{$event->id}/find-table.pdf")->assertForbidden();
});

/**
 * A published Signature event happening today with seated families.
 *
 * @return array{0: Event, 1: array<string, Household>}
 */
function p8bEventDay(): array
{
    [$event, $f] = SeatingFixtures::event([['عائلة خالد العلي', 3], ['Huda Saleh', 2], ['Sara', 2], ['Sara', 1], ['عائلة بلا هاتف', 2]], attributes: [
        'starts_at' => CarbonImmutable::now()->addHour(), 'timezone' => 'UTC',
    ]);
    $event->invitation->update(['published_at' => now(), 'slug' => 'lina-omar']);
    $phones = ['عائلة خالد العلي' => '+962 79 123 4567', 'Huda Saleh' => '0501112222'];
    foreach ($f as $name => $h) {
        $h->update(['phone' => $phones[$name] ?? ($name === 'عائلة بلا هاتف' ? null : '05000099'.random_int(10, 99))]);
    }
    SeatAssignment::query()->create(['event_id' => $event->id, 'household_id' => $f['عائلة خالد العلي']->id, 'table_id' => 'T3', 'seat_index' => 0]);
    SeatAssignment::query()->create(['event_id' => $event->id, 'household_id' => $f['عائلة بلا هاتف']->id, 'table_id' => 'T2', 'seat_index' => 0]);

    return [$event->fresh(), $f];
}

it('finds a guest\'s table by phone, or by name with the last 4 digits', function () {
    [$event] = p8bEventDay();

    $byPhone = $this->postJson('/e/lina-omar/find', ['phone' => '0791234567'])->assertOk()->json();
    expect($byPhone)->toMatchArray(['found' => true, 'seated' => true, 'table' => '3', 'label' => 'طاولة ٣'])
        ->and($byPhone['map']['mine'])->toBe('3')
        ->and($byPhone)->not->toHaveKey('name');

    // fuzzy name: no "عائلة", hamza and ya variants, one typo
    $this->postJson('/e/lina-omar/find', ['name' => 'خالد العلى', 'last4' => '٤٥٦٧'])->assertJson(['found' => true, 'table' => '3']);
    $this->postJson('/e/lina-omar/find', ['name' => 'عائله خالد العلي', 'last4' => '4567'])->assertJson(['found' => true, 'table' => '3']);
    $this->postJson('/e/lina-omar/find', ['name' => 'خالد العلق', 'last4' => '4567'])->assertJson(['found' => true]);

    // a guest on the list without a seat yet
    $this->postJson('/e/lina-omar/find', ['phone' => '0501112222'])->assertJson(['found' => true, 'seated' => false]);

    // a household without a phone: its exact name only
    $this->postJson('/e/lina-omar/find', ['name' => 'عائلة بلا هاتف'])->assertJson(['found' => true, 'table' => '2']);
    $this->postJson('/e/lina-omar/find', ['name' => 'عائلة بلا هاتفي'])->assertJson(['found' => false]);
});

it('never lists guests or tells whether a name is on the list', function () {
    p8bEventDay();
    $notFound = ['found' => false, 'message' => __('venues.find.not_found', [], 'ar')];

    // a name alone, when the household has a phone
    $this->postJson('/e/lina-omar/find', ['name' => 'Huda Saleh'])->assertExactJson($notFound);
    // the right name with the wrong digits, and a wrong name with the right digits
    $this->postJson('/e/lina-omar/find', ['name' => 'Huda Saleh', 'last4' => '0000'])->assertExactJson($notFound);
    $this->postJson('/e/lina-omar/find', ['name' => 'Someone Else', 'last4' => '2222'])->assertExactJson($notFound);
    // two households match (two "Sara"s): same answer as nobody
    $this->postJson('/e/lina-omar/find', ['name' => 'Sara'])->assertExactJson($notFound);
    // partial phone numbers and single letters
    $this->postJson('/e/lina-omar/find', ['phone' => '4567'])->assertExactJson($notFound);
    $this->postJson('/e/lina-omar/find', ['name' => 'س', 'last4' => '4567'])->assertExactJson($notFound);

    // and the page itself has no guest data
    $page = $this->get('/e/lina-omar/find')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->getContent();
    expect($page)->not->toContain('Huda')->not->toContain('خالد');
});

it('limits lookups per device and per event', function () {
    p8bEventDay();
    config(['venues.find_per_minute' => 6]);

    foreach (range(1, 6) as $i) {
        $this->postJson('/e/lina-omar/find', ['name' => "Guess {$i}", 'last4' => '1234'])->assertOk();
    }
    $this->postJson('/e/lina-omar/find', ['phone' => '0791234567'])->assertTooManyRequests();
});

it('only works on the event day and with the seating feature', function () {
    [$event] = p8bEventDay();

    $event->update(['starts_at' => now()->addDays(3)]);
    $this->get('/e/lina-omar/find')->assertOk()->assertSee(__('venues.find.closed', [], 'ar'))->assertDontSee('data-find-form', false);
    $this->postJson('/e/lina-omar/find', ['phone' => '0791234567'])->assertStatus(423)->assertJson(['error' => FindTable::STATE_CLOSED])->assertJsonMissing(['table' => '3']);

    $event->update(['starts_at' => now()->subDays(2)]);
    $this->postJson('/e/lina-omar/find', ['phone' => '0791234567'])->assertStatus(423);

    $event->update(['starts_at' => now()->addHour(), 'plan_id' => 'premium']);
    $this->get('/e/lina-omar/find')->assertNotFound();
    $this->postJson('/e/lina-omar/find', ['phone' => '0791234567'])->assertNotFound()->assertJson(['error' => FindTable::STATE_UNAVAILABLE]);

    $this->postJson('/e/no-such-event/find', ['phone' => '0791234567'])->assertNotFound();
});

it('folds Arabic names and measures typos', function () {
    expect(FindTable::fold('عائلة  الأستاذ خالد العلي'))->toBe('الاستاذ خالد العلي')
        ->and(FindTable::fold('آل سعود'))->toBe('سعود')
        ->and(FindTable::fold('مُحَمَّد'))->toBe('محمد')
        ->and(FindTable::similar('محمد الاحمد', 'محمد الاحمدي'))->toBeTrue()
        ->and(FindTable::similar('سارة', 'هدى'))->toBeFalse()
        ->and(FindTable::digits('+٩٦٢ ٧٩-١٢٣'))->toBe('96279123');
});
