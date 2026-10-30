@extends('marketing.layout')

@php
    use App\Support\SiteUrl;
    use App\Services\Marketplace\MarketplaceUrl;

    $name = $listing->name($locale);
    $cityNames = collect($listing->cities ?? [])->map(fn ($c) => __("vendors.cities.{$c}"));
    $business = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'LocalBusiness',
        '@id' => MarketplaceUrl::to('vendor', 'ar', $listing->slug).'#business',
        'name' => $name,
        'alternateName' => $locale === 'ar' ? $listing->name_en : $listing->name_ar,
        'description' => $listing->description($locale),
        'url' => MarketplaceUrl::to('vendor', $locale, $listing->slug),
        'image' => $photos ?: null,
        'telephone' => $listing->whatsapp ? '+'.$listing->whatsapp : null,
        'priceRange' => $priceRange,
        'areaServed' => $cityNames->map(fn ($c) => ['@type' => 'City', 'name' => $c])->values()->all() ?: null,
        'address' => count($listing->cities ?? []) === 1 ? ['@type' => 'PostalAddress', 'addressLocality' => $cityNames->first(), 'addressCountry' => config('marketplace.cities.'.$listing->cities[0])] : null,
        'sameAs' => $listing->instagramUrl() ? [$listing->instagramUrl()] : null,
    ]);
    $breadcrumbs = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => __('marketing.nav.home'), 'item' => SiteUrl::to('home', $locale)],
            ['@type' => 'ListItem', 'position' => 2, 'name' => __('vendors.public.title'), 'item' => MarketplaceUrl::to('vendors', $locale)],
            ['@type' => 'ListItem', 'position' => 3, 'name' => __("vendors.categories_plural.{$listing->category}"), 'item' => MarketplaceUrl::to('vendors', $locale, null, ['category' => $listing->category])],
            ['@type' => 'ListItem', 'position' => 4, 'name' => $name, 'item' => MarketplaceUrl::to('vendor', $locale, $listing->slug)],
        ],
    ];
@endphp

@push('jsonld')
    @include('marketing.partials.jsonld', ['data' => $business])
    @include('marketing.partials.jsonld', ['data' => $breadcrumbs])
@endpush

@section('content')
<section class="page-hero">
    <div class="wrap mk-vendor-hero">
        <div>
            <nav class="crumbs" aria-label="breadcrumb">
                <a href="{{ MarketplaceUrl::path('vendors', $locale) }}">{{ __('vendors.public.title') }}</a>
                <span aria-hidden="true">/</span>
                <a href="{{ MarketplaceUrl::path('vendors', $locale, null, ['category' => $listing->category]) }}">{{ __("vendors.categories_plural.{$listing->category}") }}</a>
            </nav>
            <p class="eyebrow">{{ __("vendors.categories.{$listing->category}") }}@if ($featured) <span class="mk-featured-inline">{{ __('vendors.public.featured') }}</span>@endif</p>
            <h1>{{ $name }}</h1>
            <p class="muted">{{ __('vendors.public.serves', ['cities' => $cityNames->implode($locale === 'ar' ? '، ' : ', ')]) }}</p>
            @if ($priceRange)
                <p class="mk-range">{{ __('vendors.public.price_range', ['range' => $priceRange]) }}</p>
            @endif
            <div class="actions">
                @if ($listing->whatsapp)
                    <a href="{{ route('vendors.contact', [$listing, 'whatsapp']) }}" class="btn btn-whatsapp" rel="nofollow noopener" target="_blank" data-contact="whatsapp">@include('marketing.partials.icon', ['name' => 'whatsapp']) {{ __('vendors.public.whatsapp') }}</a>
                @endif
                @if ($listing->instagram)
                    <a href="{{ route('vendors.contact', [$listing, 'instagram']) }}" class="btn btn-ghost" rel="nofollow noopener" target="_blank" data-contact="instagram" dir="ltr">{{ '@'.$listing->instagram }}</a>
                @endif
            </div>
        </div>
    </div>
</section>

@if ($photos)
    <section class="section" aria-label="{{ __('vendors.public.photos') }}">
        <div class="wrap">
            <ul class="mk-gallery">
                @foreach ($photos as $photo)
                    <li><img src="{{ $photo }}" alt="{{ __('vendors.public.photo_alt', ['name' => $name, 'n' => \App\Services\Digits::localize($loop->iteration, $locale)]) }}" loading="{{ $loop->first ? 'eager' : 'lazy' }}" decoding="async"></li>
                @endforeach
            </ul>
        </div>
    </section>
@endif

<section class="section section-tint" aria-labelledby="vendor-about">
    <div class="wrap narrow prose">
        <h2 id="vendor-about">{{ __('vendors.public.about', ['name' => $name]) }}</h2>
        @if ($listing->description($locale))
            @foreach (preg_split('/\n{2,}/', trim($listing->description($locale))) as $paragraph)
                <p>{{ $paragraph }}</p>
            @endforeach
        @else
            <p class="muted">{{ __('vendors.public.no_description') }}</p>
        @endif
        @if ($listing->occasions)
            <p class="muted">{{ __('vendors.public.occasions', ['list' => collect($listing->occasions)->map(fn ($o) => __("app.wizard.occasions.{$o}"))->implode($locale === 'ar' ? '، ' : ', ')]) }}</p>
        @endif
    </div>
</section>

@if ($calligraphy)
    <section class="section" aria-labelledby="calligraphy-offer">
        <div class="wrap">
            <div class="contact-card mk-band">
                <div>
                    <h2 id="calligraphy-offer">{{ __('vendors.calligraphy.offer_title') }}</h2>
                    <p>{{ __('vendors.calligraphy.offer_text', ['prices' => implode(' · ', $calligraphy)]) }}</p>
                </div>
                <a href="{{ route('buy.calligraphy', $listing) }}" class="btn btn-primary" rel="nofollow">{{ __('vendors.calligraphy.order') }}</a>
            </div>
        </div>
    </section>
@endif
@endsection
