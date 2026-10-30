@extends('marketing.layout')

@php
    use App\Support\SiteUrl;
    use App\Services\Marketplace\MarketplaceUrl;

    $breadcrumbs = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => __('marketing.nav.home'), 'item' => SiteUrl::to('home', $locale)],
            ['@type' => 'ListItem', 'position' => 2, 'name' => __('vendors.public.title'), 'item' => MarketplaceUrl::to('vendors', $locale)],
        ],
    ];
    $heading = $filters['category'] || $filters['city']
        ? trim(($filters['category'] ? __("vendors.categories_plural.{$filters['category']}") : __('vendors.public.title')).' '.($filters['city'] ? __('vendors.public.in_city', ['city' => __("vendors.cities.{$filters['city']}")]) : ''))
        : __('vendors.public.title');
@endphp

@push('jsonld')
    @include('marketing.partials.jsonld', ['data' => $breadcrumbs])
@endpush

@section('content')
<section class="page-hero">
    <div class="wrap">
        <nav class="crumbs" aria-label="breadcrumb">
            <a href="{{ SiteUrl::path('home', $locale) }}">{{ __('marketing.nav.home') }}</a>
            <span aria-hidden="true">/</span>
            <span>{{ __('vendors.public.title') }}</span>
        </nav>
        <h1>{{ $heading }}</h1>
        <p class="lead">{{ __('vendors.public.intro') }}</p>
    </div>
</section>

<section class="section" aria-labelledby="vendor-results">
    <div class="wrap">
        <form method="get" class="mk-filters" action="{{ MarketplaceUrl::path('vendors', $locale) }}" aria-label="{{ __('marketplace.site.filters') }}">
            <label>
                <span>{{ __('vendors.public.city') }}</span>
                <select name="city">
                    <option value="">{{ __('marketplace.site.any') }}</option>
                    @foreach ($cities as $city)
                        <option value="{{ $city }}" @selected($filters['city'] === $city)>{{ __("vendors.cities.{$city}") }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>{{ __('vendors.public.category') }}</span>
                <select name="category">
                    <option value="">{{ __('marketplace.site.any') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category }}" @selected($filters['category'] === $category)>{{ __("vendors.categories.{$category}") }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>{{ __('marketplace.site.occasion') }}</span>
                <select name="occasion">
                    <option value="">{{ __('marketplace.site.any') }}</option>
                    @foreach ($occasions as $occasion)
                        <option value="{{ $occasion }}" @selected($filters['occasion'] === $occasion)>{{ __("app.wizard.occasions.{$occasion}") }}</option>
                    @endforeach
                </select>
            </label>
            <div class="mk-filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">{{ __('marketplace.site.apply') }}</button>
                @if (array_filter($filters))
                    <a href="{{ MarketplaceUrl::path('vendors', $locale) }}" class="link-quiet">{{ __('marketplace.site.clear') }}</a>
                @endif
            </div>
        </form>

        <ul class="mk-chips" aria-label="{{ __('vendors.public.category') }}">
            @foreach ($categories as $category)
                <li><a href="{{ MarketplaceUrl::path('vendors', $locale, null, ['category' => $category, 'city' => $filters['city']]) }}" @if ($filters['category'] === $category) aria-current="page" @endif>{{ __("vendors.categories_plural.{$category}") }}</a></li>
            @endforeach
        </ul>

        <h2 id="vendor-results" class="mk-count">{{ trans_choice('vendors.public.count', $listings->total(), ['count' => \App\Services\Digits::localize($listings->total(), $locale)]) }}</h2>

        @if ($listings->isEmpty())
            <p class="muted mk-empty">{{ __('vendors.public.empty') }}</p>
        @else
            <ul class="mk-vendors">
                @foreach ($listings as $listing)
                    @include('marketplace.partials.vendor-card', ['listing' => $listing])
                @endforeach
            </ul>
            @if ($listings->hasPages())
                <nav class="mk-pages" aria-label="{{ __('marketplace.site.pages') }}">
                    @if ($listings->previousPageUrl())<a href="{{ $listings->previousPageUrl() }}" rel="prev" class="btn btn-ghost btn-sm">{{ __('marketplace.site.prev') }}</a>@endif
                    @if ($listings->nextPageUrl())<a href="{{ $listings->nextPageUrl() }}" rel="next" class="btn btn-ghost btn-sm">{{ __('marketplace.site.next') }}</a>@endif
                </nav>
            @endif
        @endif
    </div>
</section>

<section class="section section-tint" aria-labelledby="vendor-join">
    <div class="wrap mk-band">
        <div>
            <h2 id="vendor-join">{{ __('vendors.public.join_title') }}</h2>
            <p class="muted">{{ __('vendors.public.join_text') }}</p>
        </div>
        <a href="{{ route('vendor-portal.show') }}" class="btn btn-ghost" rel="nofollow">{{ __('vendors.public.join_cta') }}</a>
    </div>
</section>
@endsection
