@extends('marketing.layout')

@php
    use App\Support\SiteUrl;
    use App\Services\Marketplace\MarketplaceUrl;

    $breadcrumbs = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => __('marketing.nav.home'), 'item' => SiteUrl::to('home', $locale)],
            ['@type' => 'ListItem', 'position' => 2, 'name' => __('marketplace.site.title'), 'item' => MarketplaceUrl::to('templates', $locale)],
        ],
    ];
    $itemList = [
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'name' => __('marketplace.site.title'),
        'itemListElement' => $cards->values()->map(fn ($card, $i) => array_filter([
            '@type' => 'ListItem',
            'position' => $i + 1,
            'item' => array_filter([
                '@type' => 'Product',
                'name' => $card['name'],
                'description' => $card['description'],
                'brand' => $card['designer'] ? ['@type' => 'Brand', 'name' => $card['designer']] : null,
                'offers' => $card['price_minor'] !== null ? [
                    '@type' => 'Offer',
                    'priceCurrency' => $card['price_currency'],
                    'price' => \App\Services\Payments\Currencies::toMajor((int) $card['price_minor'], $card['price_currency']),
                    'availability' => 'https://schema.org/InStock',
                ] : null,
            ]),
        ]))->all(),
    ];
@endphp

@push('jsonld')
    @include('marketing.partials.jsonld', ['data' => $breadcrumbs])
    @include('marketing.partials.jsonld', ['data' => $itemList])
@endpush

@section('content')
<section class="page-hero">
    <div class="wrap">
        <nav class="crumbs" aria-label="breadcrumb">
            <a href="{{ SiteUrl::path('home', $locale) }}">{{ __('marketing.nav.home') }}</a>
            <span aria-hidden="true">/</span>
            <span>{{ __('marketplace.site.title') }}</span>
        </nav>
        <h1>{{ __('marketplace.site.title') }}</h1>
        <p class="lead">{{ __('marketplace.site.intro') }}</p>
    </div>
</section>

<section class="section" aria-labelledby="mk-results">
    <div class="wrap">
        <form method="get" class="mk-filters" action="{{ MarketplaceUrl::path('templates', $locale) }}" aria-label="{{ __('marketplace.site.filters') }}">
            <label>
                <span>{{ __('marketplace.site.occasion') }}</span>
                <select name="occasion">
                    <option value="">{{ __('marketplace.site.any') }}</option>
                    @foreach ($occasions as $occasion)
                        <option value="{{ $occasion }}" @selected($filters['occasion'] === $occasion)>{{ __("app.wizard.occasions.{$occasion}") }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>{{ __('marketplace.site.opening') }}</span>
                <select name="opening">
                    <option value="">{{ __('marketplace.site.any') }}</option>
                    @foreach ($openings as $opening)
                        <option value="{{ $opening->id }}" @selected($filters['opening'] === $opening->id)>{{ $locale === 'ar' ? $opening->name_ar : $opening->name_en }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                <span>{{ __('marketplace.site.designer') }}</span>
                <select name="designer">
                    <option value="">{{ __('marketplace.site.any') }}</option>
                    @foreach ($designers as $designer)
                        <option value="{{ $designer->slug }}" @selected($filters['designer'] === $designer->slug)>{{ $designer->displayName($locale) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="mk-price-filter">
                <span>{{ __('marketplace.site.max_price') }}</span>
                <span class="mk-inline">
                    <input type="number" name="max" min="0" inputmode="numeric" value="{{ $filters['max'] }}" dir="ltr" placeholder="—">
                    <select name="currency" aria-label="{{ __('marketplace.site.currency') }}">
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency }}" @selected($filters['currency'] === $currency)>{{ __("app.checkout.currencies.{$currency}") }}</option>
                        @endforeach
                    </select>
                </span>
            </label>
            <div class="mk-filter-actions">
                <button type="submit" class="btn btn-primary btn-sm">{{ __('marketplace.site.apply') }}</button>
                @if ($filters['query'])
                    <a href="{{ MarketplaceUrl::path('templates', $locale) }}" class="link-quiet">{{ __('marketplace.site.clear') }}</a>
                @endif
            </div>
        </form>

        <h2 id="mk-results" class="mk-count">{{ trans_choice('marketplace.site.count', $templates->total(), ['count' => \App\Services\Digits::localize($templates->total(), $locale)]) }}</h2>

        @if ($cards->isEmpty())
            <p class="muted mk-empty">{{ __('marketplace.site.empty') }}</p>
        @else
            <ul class="templates mk-grid">
                @foreach ($cards as $card)
                    @include('marketplace.partials.template-card', ['card' => $card, 'eager' => $loop->index < 2])
                @endforeach
            </ul>
            @if ($templates->hasPages())
                <nav class="mk-pages" aria-label="{{ __('marketplace.site.pages') }}">
                    @if ($templates->previousPageUrl())<a href="{{ $templates->previousPageUrl() }}" rel="prev" class="btn btn-ghost btn-sm">{{ __('marketplace.site.prev') }}</a>@endif
                    @if ($templates->nextPageUrl())<a href="{{ $templates->nextPageUrl() }}" rel="next" class="btn btn-ghost btn-sm">{{ __('marketplace.site.next') }}</a>@endif
                </nav>
            @endif
        @endif
    </div>
</section>

<section class="section section-tint" aria-labelledby="mk-sell">
    <div class="wrap mk-band">
        <div>
            <h2 id="mk-sell">{{ __('marketplace.site.sell_title') }}</h2>
            <p class="muted">{{ __('marketplace.site.sell_text') }}</p>
        </div>
        <a href="{{ route('designer.index') }}" class="btn btn-ghost" rel="nofollow">{{ __('marketplace.site.sell_cta') }}</a>
    </div>
</section>
@endsection
