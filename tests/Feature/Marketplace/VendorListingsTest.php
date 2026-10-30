<?php

use App\Models\AuditLog;
use App\Models\CalligraphyRequest;
use App\Models\Order;
use App\Models\User;
use App\Models\VendorContactClick;
use App\Models\VendorFeature;
use App\Models\VendorListing;
use App\Services\Marketplace\Designers;
use App\Services\Marketplace\VendorListings;
use App\Services\Payments\OrderFulfillment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Marketplace;
use Tests\Support\Payments;

beforeEach(function () {
    Payments::setUp();
    Notification::fake();
    config(['app.url' => 'https://daawa.test']);
});

function listingData(array $overrides = []): array
{
    return $overrides + [
        'name_ar' => 'عدسة دمشق',
        'name_en' => 'Damascus Lens',
        'category' => 'photographer',
        'cities' => ['damascus', 'homs'],
        'occasions' => ['wedding'],
        'description_ar' => 'تصوير أعراس.',
        'description_en' => 'Wedding photography.',
        'price_min' => 1500000,
        'price_max' => 4000000,
        'price_currency' => 'SYP',
        'whatsapp' => '+963 944 000 111',
        'instagram' => '@damascus.lens',
    ];
}

it('lets a vendor create a listing that stays private until an admin approves it', function () {
    $vendor = User::factory()->create();

    $this->actingAs($vendor)->put('/vendor-portal', listingData(['cities' => ['atlantis']]))->assertSessionHasErrors('cities.0');
    $this->actingAs($vendor)->put('/vendor-portal', listingData())->assertRedirect('/vendor-portal');

    $listing = VendorListing::query()->sole();
    expect($listing->status)->toBe('pending')
        ->and($listing->slug)->toBe('damascus-lens')
        ->and($listing->whatsapp)->toBe('963944000111')
        ->and($listing->instagram)->toBe('damascus.lens');

    $this->get('/vendors/damascus-lens')->assertNotFound();
    $this->get('/vendors')->assertOk()->assertDontSee('Damascus Lens')->assertDontSee('عدسة دمشق');

    app(VendorListings::class)->approve($listing, Marketplace::admin());
    expect(AuditLog::query()->where('action', 'vendor.approved')->exists())->toBeTrue();

    $page = $this->get('/vendors/damascus-lens')->assertOk()->getContent();
    expect($page)->toContain('<link rel="canonical" href="https://daawa.test/vendors/damascus-lens">')
        ->toContain('hreflang="en" href="https://daawa.test/en/vendors/damascus-lens"')
        ->toContain('عدسة دمشق');

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $page, $m);
    $business = collect($m[1])->map(fn ($j) => json_decode($j, true))->firstWhere('@type', 'LocalBusiness');
    expect($business)->not->toBeNull()
        ->and($business['name'])->toBe('عدسة دمشق')
        ->and($business['telephone'])->toBe('+963944000111')
        ->and(collect($business['areaServed'])->pluck('name')->all())->toBe(['دمشق', 'حمص']);

    $this->get('/en/vendors/damascus-lens')->assertOk()->assertSee('Damascus Lens');

    app(VendorListings::class)->hide($listing, Marketplace::admin(), 'Complaints');
    $this->get('/vendors/damascus-lens')->assertNotFound();
});

it('stores listing photos under vendors/{id} and keeps vendors to their own listing', function () {
    Storage::fake('public');
    $listing = Marketplace::listing();
    $other = Marketplace::listing();

    $this->actingAs($listing->user)->post('/vendor-portal/photos', ['photo' => UploadedFile::fake()->image('work.jpg', 1200, 900)])->assertRedirect();
    $path = $listing->fresh()->photos[0];
    expect($path)->toStartWith("vendors/{$listing->id}/");
    Storage::disk('public')->assertExists($path);

    // the portal only ever shows and edits the signed-in vendor's listing
    $this->actingAs($other->user)->get('/vendor-portal')->assertInertia(fn (Assert $page) => $page->where('listing.slug', $other->slug));
    $this->actingAs($other->user)->put('/vendor-portal', listingData(['name_en' => 'Mine']));
    expect($listing->fresh()->name_en)->not->toBe('Mine')->and($other->fresh()->name_en)->toBe('Mine');

    $this->actingAs(User::factory()->create())->post('/vendor-portal/photos', ['photo' => UploadedFile::fake()->image('x.jpg')])->assertNotFound();
});

it('lists featured vendors first with a badge until the spot expires', function () {
    $first = Marketplace::listing(data: ['name_ar' => 'أ مصوّر', 'name_en' => 'A Photo']);
    $second = Marketplace::listing(data: ['name_ar' => 'ب مصوّر', 'name_en' => 'B Photo']);
    $riyadh = Marketplace::listing(data: ['name_ar' => 'ت مصوّر', 'name_en' => 'C Photo', 'cities' => ['riyadh']]);
    $listings = app(VendorListings::class);

    expect($listings->search(['city' => 'damascus'])->pluck('id')->all())->toBe([$first->id, $second->id]);

    $listings->feature($second, 'damascus', 30, null, Marketplace::admin());
    expect($listings->search(['city' => 'damascus'])->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and($listings->search(['city' => 'riyadh'])->pluck('id')->all())->toBe([$riyadh->id]);

    $html = $this->get('/vendors?city=damascus')->assertOk()->getContent();
    expect(strpos($html, 'data-vendor="'.$second->slug.'"'))->toBeLessThan(strpos($html, 'data-vendor="'.$first->slug.'"'));
    expect($html)->toContain('is-featured');

    // featured only where it was bought: Homs lists them by name
    expect($listings->search(['city' => 'homs'])->pluck('id')->first())->toBeNull();

    $this->travel(31)->days();
    expect($listings->search(['city' => 'damascus'])->pluck('id')->all())->toBe([$first->id, $second->id]);

    $this->artisan('vendors:end-features')->assertSuccessful();
    expect(VendorFeature::query()->sole()->ended_at)->not->toBeNull();
});

it('sells a featured spot through the checkout and ends it on refund', function () {
    $listing = Marketplace::listing(User::factory()->create(['email_verified_at' => now()]));

    $this->actingAs($listing->user)->get('/buy/feature')->assertInertia(fn (Assert $page) => $page->component('marketplace/Buy')->where('item.kind', 'feature')->where('item.prices.SAR', 10900));
    $this->actingAs($listing->user)->post('/buy/feature', ['city' => 'riyadh', 'currency' => 'SAR', 'gateway' => 'transfer'])->assertSessionHasErrors('city');
    $this->actingAs($listing->user)->post('/buy/feature', ['city' => 'damascus', 'currency' => 'SAR', 'gateway' => 'transfer'])->assertRedirect();

    $order = Order::query()->sole();
    expect($order->event_id)->toBeNull()
        ->and($order->items[0])->toMatchArray(['type' => 'vendor_feature', 'id' => $listing->id, 'city' => 'damascus', 'category' => 'photographer', 'days' => 30, 'amount' => 10900]);

    app(OrderFulfillment::class)->approveTransfer($order, Marketplace::admin(), 'FEAT-1');
    $feature = VendorFeature::query()->sole();
    expect($feature->source_order_id)->toBe($order->id)
        ->and((int) round($feature->starts_at->diffInDays($feature->ends_at)))->toBe(30)
        ->and(VendorListings::isFeatured($listing, 'damascus'))->toBeTrue();

    app(OrderFulfillment::class)->refund($order->fresh(), Marketplace::admin());
    expect(VendorListings::isFeatured($listing, 'damascus'))->toBeFalse();

    // a pending listing can't buy one
    $pending = Marketplace::listing(User::factory()->create(['email_verified_at' => now()]), approve: false);
    $this->actingAs($pending->user)->get('/buy/feature')->assertForbidden();
});

it('counts contact taps anonymously and goes straight to WhatsApp', function () {
    $listing = Marketplace::listing();

    $location = $this->get("/go/vendor/{$listing->slug}/whatsapp")->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith('https://wa.me/963944000111?text=');
    $this->get("/go/vendor/{$listing->slug}/whatsapp");
    $this->get("/go/vendor/{$listing->slug}/instagram")->assertNotFound(); // no Instagram on this listing

    $row = VendorContactClick::query()->sole();
    expect($row->count)->toBe(2)->and($row->channel)->toBe('whatsapp')
        ->and(array_keys($row->getAttributes()))->toBe(['id', 'listing_id', 'channel', 'day', 'count']);
    expect(app(VendorListings::class)->clicks($listing))->toBe(['whatsapp' => 2]);
});

it('lists approved vendors and designers in the sitemap, and keeps portals out of robots', function () {
    $listing = Marketplace::listing();
    $pending = Marketplace::listing(approve: false);
    $template = Marketplace::published();
    $slug = $template->designerProfile->slug;

    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($xml)->toContain('<loc>https://daawa.test/vendors/'.$listing->slug.'</loc>')
        ->toContain('<loc>https://daawa.test/en/vendors/'.$listing->slug.'</loc>')
        ->toContain('<loc>https://daawa.test/templates</loc>')
        ->toContain('<loc>https://daawa.test/designers/'.$slug.'</loc>')
        ->not->toContain('/vendors/'.$pending->slug.'<');
    expect(simplexml_load_string($xml))->not->toBeFalse();

    $robots = $this->get('/robots.txt')->getContent();
    expect($robots)->toContain('Disallow: /designer/')->toContain('Disallow: /vendor-portal')->toContain('Disallow: /go/');
});

it('shows the marketplace pages with SEO, filters and designer pages', function () {
    $template = Marketplace::published();
    $profile = $template->designerProfile;

    $html = $this->get('/templates')->assertOk()->getContent();
    expect($html)->toContain('ياسمين الشام')->toContain('<link rel="canonical" href="https://daawa.test/templates">')->toContain('"@type":"ItemList"');

    $this->get('/en/templates?occasion=wedding&opening=rose')->assertOk()->assertSee('Damascus Jasmine');
    $this->get('/en/templates?occasion=birthday')->assertOk()->assertDontSee('Damascus Jasmine');
    $this->get('/en/templates?max=10&currency=USD')->assertOk()->assertDontSee('Damascus Jasmine');
    $this->get('/en/templates?max=12&currency=USD')->assertOk()->assertSee('Damascus Jasmine');
    $this->get('/en/templates?designer='.$profile->slug)->assertOk()->assertSee('Damascus Jasmine');

    $this->get('/designers/'.$profile->slug)->assertOk()->assertSee($profile->display_name_ar)->assertSee('"@type":"Person"', false);
    $this->get('/designers/nobody')->assertNotFound();

    // a suspended designer's page and designs disappear
    app(Designers::class)->suspend($profile, Marketplace::admin(), 'x');
    $this->get('/designers/'.$profile->slug)->assertNotFound();
    $this->get('/templates')->assertDontSee('ياسمين الشام');
});

it('orders a calligraphy name logo and lets only its calligrapher move it along', function () {
    $calligrapher = Marketplace::listing(data: ['category' => 'calligraphy', 'calligraphy_prices' => ['USD' => 2500, 'SAR' => 9500]]);
    $host = User::factory()->create(['email_verified_at' => now()]);

    $this->get("/vendors/{$calligrapher->slug}")->assertSee(route('buy.calligraphy', $calligrapher), false);
    $this->actingAs($host)->post("/buy/calligraphy/{$calligrapher->slug}", ['names' => 'ريم وعمر', 'brief' => 'Diwani, gold', 'currency' => 'SAR', 'gateway' => 'transfer'])->assertRedirect();

    $request = CalligraphyRequest::query()->sole();
    $order = Order::query()->sole();
    expect($request->status)->toBe('awaiting_payment')->and($order->items[0]['request_id'])->toBe($request->id)->and($order->total_cents)->toBe(9500);

    // unpaid: not in the calligrapher's list
    $this->actingAs($calligrapher->user)->get('/vendor-portal')->assertInertia(fn (Assert $page) => $page->has('listing.requests', 0));

    app(OrderFulfillment::class)->approveTransfer($order, Marketplace::admin(), 'CAL-1');
    expect($request->fresh()->status)->toBe('new');
    $this->actingAs($calligrapher->user)->get('/vendor-portal')->assertInertia(fn (Assert $page) => $page->where('listing.requests.0.names', 'ريم وعمر'));

    $this->actingAs(Marketplace::listing()->user)->patch("/vendor-portal/calligraphy/{$request->id}", ['status' => 'delivered'])->assertForbidden();
    $this->actingAs($calligrapher->user)->patch("/vendor-portal/calligraphy/{$request->id}", ['status' => 'delivered'])->assertRedirect();
    expect($request->fresh()->status)->toBe('delivered')->and($request->fresh()->delivered_at)->not->toBeNull();

    // calligraphy prices stay within the bounds
    $this->actingAs($calligrapher->user)->put('/vendor-portal', listingData(['category' => 'calligraphy', 'calligraphy_prices' => ['USD' => 100]]))->assertSessionHasErrors('calligraphy_prices.USD');
});

it('shows vendors near the venue on the host dashboard', function () {
    $listing = Marketplace::listing(data: ['occasions' => ['wedding']]);
    Marketplace::listing(data: ['cities' => ['riyadh'], 'name_en' => 'Far Away', 'name_ar' => 'بعيد']);
    $host = User::factory()->create();
    $event = Payments::event($host, ['status' => 'active', 'occasion' => 'wedding', 'address' => 'دمشق، المزة', 'timezone' => 'Asia/Damascus']);

    expect(VendorListings::cityFor($event))->toBe('damascus');
    $this->actingAs($host)->get("/dashboard/events/{$event->id}")->assertInertia(fn (Assert $page) => $page
        ->component('dashboard/Event')
        ->where('vendors.city', 'damascus')
        ->has('vendors.items', 1)
        ->where('vendors.items.0.slug', $listing->slug)
        ->where('vendors.items.0.whatsapp', "/go/vendor/{$listing->slug}/whatsapp"));
});
