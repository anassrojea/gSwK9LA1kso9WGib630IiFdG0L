<?php

use App\Models\Invitation;
use App\Models\Plan;
use App\Models\SiteCounter;
use App\Models\User;
use App\Services\Payments\PriceList;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    config(['app.url' => 'https://daawa.test', 'app.invite_url' => 'https://daawa.link']);
    $this->seed([PlanSeeder::class, CatalogSeeder::class]);
});

dataset('site pages', [
    'home' => ['/', '/en'],
    'pricing' => ['/pricing', '/en/pricing'],
    'about' => ['/about', '/en/about'],
    'wedding' => ['/occasions/wedding', '/en/occasions/wedding'],
    'baby welcome' => ['/occasions/baby-welcome', '/en/occasions/baby-welcome'],
    'terms' => ['/legal/terms', '/en/legal/terms'],
    'privacy' => ['/legal/privacy', '/en/legal/privacy'],
    'refund' => ['/legal/refund', '/en/legal/refund'],
    'cookies' => ['/legal/cookies', '/en/legal/cookies'],
]);

it('renders every site page in Arabic and English with canonical and hreflang links', function (string $ar, string $en) {
    $arUrl = 'https://daawa.test'.($ar === '/' ? '/' : $ar);
    $enUrl = 'https://daawa.test'.$en;

    foreach ([[$ar, 'ar', 'rtl', $arUrl], [$en, 'en', 'ltr', $enUrl]] as [$path, $lang, $dir, $canonical]) {
        $this->get($path)
            ->assertOk()
            ->assertSee("<html lang=\"{$lang}\" dir=\"{$dir}\">", false)
            ->assertSee("<link rel=\"canonical\" href=\"{$canonical}\">", false)
            ->assertSee("<link rel=\"alternate\" hreflang=\"ar\" href=\"{$arUrl}\">", false)
            ->assertSee("<link rel=\"alternate\" hreflang=\"en\" href=\"{$enUrl}\">", false)
            ->assertSee('<link rel="alternate" hreflang="x-default"', false)
            ->assertSee('property="og:image"', false)
            ->assertSee('"@type":"Organization"', false)
            ->assertDontSee('noindex');
    }
})->with('site pages');

it('keeps the language of the URL over the session', function () {
    $this->get('/locale/en');
    $this->get('/pricing')->assertSee('lang="ar"', false);
    $this->get('/en/pricing')->assertSee('lang="en"', false);
});

it('links every legal page from the footer', function () {
    $this->get('/')->assertSee('href="/legal/terms"', false)->assertSee('href="/legal/privacy"', false)
        ->assertSee('href="/legal/refund"', false)->assertSee('href="/legal/cookies"', false);
    $this->get('/en')->assertSee('href="/en/legal/terms"', false);
});

it('describes the landing page with FAQ and WebSite structured data and lists the openings', function () {
    $this->get('/')
        ->assertSee('"@type":"FAQPage"', false)
        ->assertSee('"@type":"WebSite"', false)
        ->assertSee(__('marketing.openings.items.rose', [], 'ar'))
        ->assertSee('id="consent"', false);
});

it('links the demo invitations outside production only', function () {
    $this->seed(DemoSeeder::class);

    $this->get('/')->assertSee('https://daawa.link/i/LayaliDemo01', false);

    app()->detectEnvironment(fn () => 'production');
    config(['marketing.showcase_tokens' => ['WardaDemo001']]);
    $this->get('/')->assertDontSee('LayaliDemo01')->assertSee('https://daawa.link/i/WardaDemo001', false);
});

it('lists the occasion templates from the database with a form that starts a draft', function () {
    $this->get('/en/occasions/wedding')
        ->assertOk()
        ->assertSee('Layali')
        ->assertSee('Warda')
        ->assertSee('name="template_id" value="layali-envelope"', false)
        ->assertSee('"@type":"BreadcrumbList"', false);

    $this->get('/occasions/funeral')->assertNotFound();
});

it('shows plan and add-on prices from the prices table in the chosen currency', function () {
    $prices = app(PriceList::class);
    $prices->set(Plan::find('premium'), 'SAR', 11900);
    $prices->set(Plan::find('premium'), 'SYP', 400000);

    $this->get('/en/pricing?currency=SAR')
        ->assertOk()
        ->assertSee('SAR', false)
        ->assertSee('119')
        ->assertSee('"priceCurrency":"SAR"', false)
        ->assertSee('"@type":"Product"', false)
        ->assertSee('aria-current="true"  data-currency="SAR"', false);

    $this->get('/en/pricing?currency=SYP')->assertSee('400,000')->assertSee('"priceCurrency":"SYP"', false);
    $this->get('/en/pricing?currency=USD')->assertSee('$29')->assertSee('$79')->assertSee('$15');
    $this->get('/pricing?currency=SAR')->assertSee('١١٩');

    // the visitor's country (Cloudflare) picks the default
    $this->get('/en/pricing', ['CF-IPCountry' => 'SY'])->assertSee('aria-current="true"  data-currency="SYP"', false);
    $this->get('/en/pricing')->assertSee('aria-current="true"  data-currency="USD"', false);
    $this->get('/pricing')->assertSee('aria-current="true"  data-currency="SAR"', false);
});

it('renders the legal pages from Markdown with the draft note and the account privacy link', function () {
    $this->get('/en/legal/privacy')
        ->assertOk()
        ->assertSee('<!-- DRAFT: review with a lawyer', false)
        ->assertSee('href="/account/privacy"', false)
        ->assertSee('Premium keeps it 30 days after the event')
        ->assertDontSee(':retention_list');

    $this->get('/legal/cookies')->assertSee('data-consent-reset', false);
    $this->get('/legal/unknown')->assertNotFound();
});

it('lists only public site pages in the sitemap', function () {
    $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    $xml = $response->getContent();

    expect($xml)
        ->toContain('<loc>https://daawa.test/</loc>')
        ->toContain('<loc>https://daawa.test/en/pricing</loc>')
        ->toContain('<loc>https://daawa.test/occasions/iftar</loc>')
        ->toContain('hreflang="en" href="https://daawa.test/en/legal/refund"')
        ->not->toContain('/i/')
        ->not->toContain('/dashboard')
        ->not->toContain('/create');

    expect(simplexml_load_string($xml))->not->toBeFalse();
    $response->assertHeaderMissing('Set-Cookie');
});

it('disallows private areas in robots.txt', function () {
    $body = $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();

    $everyone = explode("\n\n", $body)[0];
    foreach (['/i/', '/p/', '/e/', '/dashboard', '/create', '/admin', '/account'] as $path) {
        expect($everyone)->toContain("Disallow: {$path}");
    }
    expect($body)->toContain('Sitemap: https://daawa.test/sitemap.xml');
});

it('counts the "design yours" click anonymously and lands on the home page with UTM parameters', function () {
    [$event] = invitedHousehold();
    $slug = Invitation::query()->where('event_id', $event->id)->value('slug');

    $this->get("/r/design-yours?e={$slug}", ['User-Agent' => 'Mozilla/5.0 (Linux; Android 13)'])
        ->assertRedirect('https://daawa.test/?utm_source=invite&utm_medium=footer&utm_campaign=design_yours')
        ->assertHeaderMissing('Set-Cookie');

    $this->get('/r/design-yours', ['User-Agent' => 'Mozilla/5.0'])->assertRedirect();
    $this->get('/r/design-yours?e='.$slug, ['User-Agent' => 'WhatsApp/2.23.20.0'])->assertRedirect();

    expect(SiteCounter::total(SiteCounter::DESIGN_YOURS, $event->id))->toBe(1)
        ->and(SiteCounter::total(SiteCounter::DESIGN_YOURS))->toBe(2)
        ->and(SiteCounter::query()->first()->getAttributes())->toHaveKeys(['name', 'subject', 'day', 'count']);
});

it('puts the "design yours" link and the privacy link on the guest invitation', function () {
    [$event, $household] = invitedHousehold();
    $slug = Invitation::query()->where('event_id', $event->id)->value('slug');

    $this->get("/i/{$household->token}")
        ->assertOk()
        ->assertSee("https://daawa.test/r/design-yours?e={$slug}", false)
        ->assertSee('https://daawa.test/legal/privacy', false)
        ->assertDontSee('marketing.js')
        ->assertDontSee('posthog');
});

it('opens /pulse to admins only', function () {
    $this->get('/pulse')->assertForbidden();
    $this->actingAs(User::factory()->create())->get('/pulse')->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get('/pulse')->assertOk();
});
