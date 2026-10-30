<?php

use App\Filament\Resources\VenueListings\Pages\ListVenueListings;
use App\Models\AuditLog;
use App\Models\Entitlement;
use App\Models\HallLayout;
use App\Models\Organization;
use App\Models\User;
use App\Models\VenueProfile;
use App\Services\HallGenerator;
use App\Services\Venues\VenueFeatures;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\VenueFixtures;

/*
| The public venue directory (phase 8B, plan section 13.1): listing gating,
| admin approval, the pages in Arabic and English, SEO and the sitemap.
*/

beforeEach(function () {
    config(['app.url' => 'https://daawa.app']);
    VenueFixtures::plans();
});

/** An approved, opted-in venue on Venue Pro with one public hall. */
function p8bVenue(array $profile = [], ?string $plan = 'venue_pro', string $name = 'قاعة النور'): VenueProfile
{
    $org = Organization::factory()->venue()->create(['name' => $name, 'plan_id' => $plan]);
    $venue = VenueProfile::factory()->approved()->create(['org_id' => $org->id, 'slug' => 'al-noor-'.strtolower(Str::random(6)), 'name_en' => 'Al Noor Hall'] + $profile);
    HallLayout::query()->create(['org_id' => $org->id, 'name' => 'Ballroom', 'public' => true, 'layout' => app(HallGenerator::class)->generate(6, 8, 'split', 'rect', 'Ballroom')]);
    HallLayout::query()->create(['org_id' => $org->id, 'name' => 'Private room', 'public' => false, 'layout' => app(HallGenerator::class)->generate(2, 8)]);

    return $venue->fresh();
}

it('lists a venue only when it opted in, an admin approved it and its plan includes the directory', function () {
    $live = p8bVenue();
    $noPlan = p8bVenue(plan: null, name: 'No plan hall');
    $premium = p8bVenue(plan: 'premium', name: 'Premium org');
    $pending = p8bVenue(['status' => VenueProfile::STATUS_PENDING], name: 'Pending hall');
    $hidden = p8bVenue(['status' => VenueProfile::STATUS_HIDDEN], name: 'Hidden hall');
    $optedOut = p8bVenue(['listed' => false], name: 'Opted out hall');

    $html = $this->get('/venues')->assertOk()->getContent();
    expect($html)->toContain('قاعة النور')
        ->not->toContain('No plan hall')->not->toContain('Premium org')->not->toContain('Pending hall')
        ->not->toContain('Hidden hall')->not->toContain('Opted out hall');

    $this->get("/venues/{$live->slug}")->assertOk();
    foreach ([$noPlan, $premium, $pending, $hidden, $optedOut] as $venue) {
        $this->get("/venues/{$venue->slug}")->assertNotFound();
    }

    // an org-level entitlement unlocks the listing without the plan; an expired one does not
    Entitlement::query()->create(['org_id' => $noPlan->org_id, 'feature' => VenueFeatures::DIRECTORY_LISTING]);
    Entitlement::query()->create(['org_id' => $premium->org_id, 'feature' => VenueFeatures::DIRECTORY_LISTING, 'until' => now()->subDay()]);
    $this->get("/venues/{$noPlan->slug}")->assertOk();
    $this->get("/venues/{$premium->slug}")->assertNotFound();
});

it('filters by city and type, searches by name or area, featured first', function () {
    $riyadh = p8bVenue(['city' => 'riyadh', 'venue_type' => 'hall', 'area' => 'الملقا'], name: 'قاعة الملقا');
    $jeddah = p8bVenue(['city' => 'jeddah', 'venue_type' => 'hotel', 'featured' => true], name: 'فندق اللؤلؤة');

    $this->get('/venues?city=jeddah')->assertOk()->assertSee('فندق اللؤلؤة')->assertDontSee('قاعة الملقا');
    $this->get('/venues?type=hall')->assertOk()->assertSee('قاعة الملقا')->assertDontSee('فندق اللؤلؤة');
    $this->get('/venues?q='.urlencode('الملقا'))->assertOk()->assertSee('قاعة الملقا')->assertDontSee('فندق اللؤلؤة');
    $this->get('/en/venues?q=pearl')->assertOk()->assertSee('No venue matches');
    // unknown filters are ignored, not errors
    $this->get('/venues?city=atlantis&type=castle')->assertOk()->assertSee('قاعة الملقا');

    $html = $this->get('/venues')->getContent();
    expect(strpos($html, 'فندق اللؤلؤة'))->toBeLessThan(strpos($html, 'قاعة الملقا'));
});

it('shows the venue page with its public halls, amenities, WhatsApp, map and the create button', function () {
    $venue = p8bVenue(['amenities' => ['parking', 'prayer_room'], 'map_url' => 'https://maps.app.goo.gl/abc', 'capacity' => null]);

    $html = $this->get("/venues/{$venue->slug}")->assertOk()->getContent();
    $hall = HallLayout::query()->where('org_id', $venue->org_id)->where('public', true)->sole();

    expect($html)->toContain('قاعة النور')->toContain('Ballroom')->not->toContain('Private room')
        ->toContain('<svg class="hall"')->toContain('h-rect')
        ->toContain('مواقف سيارات')->toContain('مصلى')
        ->toContain('https://wa.me/966500000001?text=')
        ->toContain('https://maps.app.goo.gl/abc')
        ->toContain('name="hall_layout_id" value="'.$hall->id.'"')
        ->toContain('action="'.route('create.store').'"')
        // capacity from the biggest public hall, in Arabic digits
        ->toContain('حتى ٤٨ ضيف');

    $this->get("/en/venues/{$venue->slug}")->assertOk()->assertSee('Al Noor Hall')->assertSee('Up to 48 guests')->assertSee('Create your invitation at this venue');
});

it('has canonical, hreflang and EventVenue structured data', function () {
    $venue = p8bVenue(['lat' => 24.7, 'lng' => 46.6]);

    $html = $this->get("/venues/{$venue->slug}")->getContent();
    expect($html)->toContain('<link rel="canonical" href="https://daawa.app/venues/'.$venue->slug.'">')
        ->toContain('<link rel="alternate" hreflang="en" href="https://daawa.app/en/venues/'.$venue->slug.'">')
        ->toContain('<link rel="alternate" hreflang="x-default" href="https://daawa.app/venues/'.$venue->slug.'">');

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    $ld = collect($m[1])->map(fn ($j) => json_decode($j, true))->firstWhere('@type', ['EventVenue', 'LocalBusiness']);
    expect($ld)->not->toBeNull()
        ->and($ld['name'])->toBe('قاعة النور')
        ->and($ld['url'])->toBe('https://daawa.app/venues/'.$venue->slug)
        ->and($ld['address']['addressLocality'])->toBe('الرياض')
        ->and($ld['address']['addressCountry'])->toBe('SA')
        ->and($ld['geo'])->toBe(['@type' => 'GeoCoordinates', 'latitude' => 24.7, 'longitude' => 46.6])
        ->and($ld['maximumAttendeeCapacity'])->toBe(400)
        ->and($ld['amenityFeature'])->toHaveCount(3);

    // the list page: canonical, and a city page is its own canonical
    expect($this->get('/venues')->getContent())->toContain('<link rel="canonical" href="https://daawa.app/venues">');
    expect($this->get('/venues?city=riyadh')->getContent())->toContain('<link rel="canonical" href="https://daawa.app/venues?city=riyadh">')->not->toContain('noindex');
    expect($this->get('/venues?q=x')->getContent())->toContain('<meta name="robots" content="noindex, follow">');
});

it('lists the directory and every public venue in the sitemap', function () {
    $live = p8bVenue();
    $hidden = p8bVenue(['status' => VenueProfile::STATUS_HIDDEN], name: 'Hidden');

    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($xml)->toContain('<loc>https://daawa.app/venues</loc>')
        ->toContain('<loc>https://daawa.app/en/venues</loc>')
        ->toContain('<loc>https://daawa.app/venues/'.$live->slug.'</loc>')
        ->toContain('hreflang="en" href="https://daawa.app/en/venues/'.$live->slug.'"')
        ->not->toContain($hidden->slug);

    expect($this->get('/robots.txt')->getContent())->toContain('Disallow: /v/');
});

it('lets the admin approve, hide and feature listings, with an audit row', function () {
    $admin = User::factory()->admin()->create();
    $venue = p8bVenue(['status' => VenueProfile::STATUS_PENDING]);
    $this->actingAs($admin);

    Livewire::test(ListVenueListings::class)
        ->assertCanSeeTableRecords([$venue])
        ->callAction(TestAction::make('approve')->table($venue))
        ->assertNotified(trans('venues.admin.done_approve'));
    expect($venue->fresh()->status)->toBe(VenueProfile::STATUS_APPROVED)->and($venue->fresh()->approved_by)->toBe($admin->id);
    $this->get("/venues/{$venue->slug}")->assertOk();

    Livewire::test(ListVenueListings::class)->callAction(TestAction::make('feature')->table($venue));
    expect($venue->fresh()->featured)->toBeTrue();

    Livewire::test(ListVenueListings::class)->callAction(TestAction::make('hide')->table($venue));
    expect($venue->fresh()->status)->toBe(VenueProfile::STATUS_HIDDEN)->and($venue->fresh()->featured)->toBeFalse();
    $this->get("/venues/{$venue->slug}")->assertNotFound();

    expect(AuditLog::query()->where('entity', 'VenueProfile')->pluck('action')->all())->toBe(['venue.approve', 'venue.feature', 'venue.hide']);
});

it('keeps the listing resource to admins', function () {
    $this->actingAs(User::factory()->create())->get('/admin/venue-listings')->assertForbidden();
});
