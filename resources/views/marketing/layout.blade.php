@php
    use App\Support\SiteUrl;
    use App\Http\Controllers\Marketing\SiteController;

    $other = $locale === 'ar' ? 'en' : 'ar';
    $switchUrl = $seo['alternates'][$other] ?? SiteUrl::to('home', $other);
    $nav = [
        ['href' => SiteUrl::path('home', $locale).'#occasions', 'label' => __('marketing.nav.occasions'), 'page' => 'occasion'],
        ['href' => \App\Services\Marketplace\MarketplaceUrl::path('templates', $locale), 'label' => __('marketplace.site.nav'), 'page' => 'templates'],
        ['href' => \App\Services\Marketplace\MarketplaceUrl::path('vendors', $locale), 'label' => __('vendors.public.nav'), 'page' => 'vendors'],
        ['href' => SiteUrl::path('pricing', $locale), 'label' => __('marketing.nav.pricing'), 'page' => 'pricing'],
        ['href' => SiteUrl::path('about', $locale), 'label' => __('marketing.nav.about'), 'page' => 'about'],
    ];
    $organization = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        '@id' => rtrim(config('app.url'), '/').'/#organization',
        'name' => 'Daawa',
        'alternateName' => 'دعوة',
        'url' => SiteUrl::to('home', 'ar'),
        'logo' => asset('images/logo-512.png'),
        'description' => __('marketing.ld.org_description'),
        'sameAs' => config('marketing.social') ?: null,
        'contactPoint' => ($wa = SiteController::whatsappUrl()) ? [[
            '@type' => 'ContactPoint',
            'contactType' => 'customer support',
            'url' => $wa,
            'availableLanguage' => ['ar', 'en'],
        ]] : null,
    ]);
    // the brand mark's display font, subset to its own letters (plan section 21)
    $brandFont = 'https://fonts.googleapis.com/css2?family=Katibeh&display=swap&text='.rawurlencode('دعوةDaw');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <link rel="canonical" href="{{ $seo['canonical'] }}">
    @foreach ($seo['alternates'] as $lang => $href)
        <link rel="alternate" hreflang="{{ $lang }}" href="{{ $href }}">
    @endforeach
    <meta name="theme-color" content="#5A1A2C">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ __('marketing.brand') }}">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    <meta property="og:locale" content="{{ $locale === 'ar' ? 'ar_AR' : 'en_US' }}">
    <meta property="og:locale:alternate" content="{{ $locale === 'ar' ? 'en_US' : 'ar_AR' }}">
    <meta property="og:image" content="{{ $seo['image'] }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ __('marketing.brand') }}">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700&display=swap">
    <link rel="stylesheet" href="{{ $brandFont }}">
    @vite(['resources/css/marketing.css', 'resources/js/marketing.js'])
    @include('marketing.partials.jsonld', ['data' => $organization])
    @stack('jsonld')
</head>
<body class="site" data-page="{{ $seo['page'] }}">
<a href="#main" class="skip">{{ __('marketing.skip') }}</a>

<header class="site-header">
    <div class="wrap header-row">
        <a href="{{ SiteUrl::path('home', $locale) }}" class="brand" aria-label="{{ __('marketing.brand') }}">
            <span class="brand-mark" aria-hidden="true">دعوة</span>
            <span class="brand-latin" aria-hidden="true">Daawa</span>
        </a>
        <nav aria-label="{{ __('marketing.nav.label') }}" class="main-nav">
            @foreach ($nav as $item)
                <a href="{{ $item['href'] }}" @if ($seo['page'] === $item['page']) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
        </nav>
        <div class="header-actions">
            <a href="{{ $switchUrl }}" hreflang="{{ $other }}" lang="{{ $other }}" class="lang" aria-label="{{ __('marketing.nav.language_label') }}">{{ __('marketing.nav.language') }}</a>
            @auth
                <a href="{{ route('dashboard') }}" class="link-quiet hide-sm">{{ __('marketing.nav.dashboard') }}</a>
            @else
                <a href="{{ route('login') }}" class="link-quiet hide-sm" rel="nofollow">{{ __('marketing.nav.login') }}</a>
            @endauth
            <a href="{{ route('create') }}" class="btn btn-primary btn-sm" data-track="cta_create" data-track-place="header" rel="nofollow">{{ __('marketing.nav.create') }}</a>
        </div>
    </div>
    <nav aria-label="{{ __('marketing.nav.label') }}" class="wrap sub-nav">
        @foreach ($nav as $item)
            <a href="{{ $item['href'] }}" @if ($seo['page'] === $item['page']) aria-current="page" @endif>{{ $item['label'] }}</a>
        @endforeach
        @guest
            <a href="{{ route('login') }}" rel="nofollow">{{ __('marketing.nav.login') }}</a>
        @endguest
    </nav>
</header>

<main id="main">
    @yield('content')
</main>

<footer class="site-footer">
    <div class="wrap footer-grid">
        <div class="footer-brand">
            <a href="{{ SiteUrl::path('home', $locale) }}" class="brand"><span class="brand-mark">دعوة</span><span class="brand-latin">Daawa</span></a>
            <p>{{ __('marketing.footer.tagline') }}</p>
            <a href="{{ $switchUrl }}" hreflang="{{ $other }}" lang="{{ $other }}" class="lang">{{ __('marketing.nav.language') }}</a>
        </div>
        <div>
            <h2>{{ __('marketing.footer.product') }}</h2>
            <ul>
                @foreach (array_slice(config('marketing.occasions'), 0, 4, true) as $slug => $value)
                    <li><a href="{{ SiteUrl::path('occasion', $locale, $slug) }}">{{ __("marketing.occasions.items.{$value}.name") }}</a></li>
                @endforeach
                <li><a href="{{ SiteUrl::path('pricing', $locale) }}">{{ __('marketing.nav.pricing') }}</a></li>
                <li><a href="{{ SiteUrl::path('about', $locale) }}">{{ __('marketing.nav.about') }}</a></li>
            </ul>
        </div>
        <div>
            <h2>{{ __('marketing.footer.legal') }}</h2>
            <ul>
                @foreach (SiteUrl::LEGAL as $page)
                    <li><a href="{{ SiteUrl::path('legal', $locale, $page) }}">{{ __("legal.pages.{$page}.title") }}</a></li>
                @endforeach
            </ul>
        </div>
    </div>
    <p class="wrap copyright">{{ __('marketing.footer.rights', ['year' => \App\Services\Digits::localize(now()->year, $locale)]) }}</p>
</footer>

{{-- cookie consent (plan section 20): measurement loads only after "Accept"; resources/js/marketing.js --}}
<div class="consent" id="consent" role="region" aria-label="{{ __('marketing.consent.label') }}" hidden>
    <p>{{ __('marketing.consent.text') }} <a href="{{ SiteUrl::path('legal', $locale, 'cookies') }}">{{ __('marketing.consent.more') }}</a></p>
    <div class="consent-actions">
        <button type="button" class="btn btn-ghost btn-sm" data-consent="denied">{{ __('marketing.consent.decline') }}</button>
        <button type="button" class="btn btn-primary btn-sm" data-consent="granted">{{ __('marketing.consent.accept') }}</button>
    </div>
</div>
</body>
</html>
