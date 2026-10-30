@extends('marketing.layout')

@php
    use App\Support\SiteUrl;
    use App\Services\Marketplace\MarketplaceUrl;

    $name = $designer->displayName($locale);
    $person = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Person',
        'name' => $name,
        'alternateName' => $locale === 'ar' ? $designer->display_name_en : $designer->display_name_ar,
        'description' => $designer->bio($locale),
        'url' => MarketplaceUrl::to('designer', $locale, $designer->slug),
        'sameAs' => array_values($designer->portfolio_links ?? []) ?: null,
    ]);
    $breadcrumbs = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => __('marketing.nav.home'), 'item' => SiteUrl::to('home', $locale)],
            ['@type' => 'ListItem', 'position' => 2, 'name' => __('marketplace.site.title'), 'item' => MarketplaceUrl::to('templates', $locale)],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $name, 'item' => MarketplaceUrl::to('designer', $locale, $designer->slug)],
        ],
    ];
@endphp

@push('jsonld')
    @include('marketing.partials.jsonld', ['data' => $person])
    @include('marketing.partials.jsonld', ['data' => $breadcrumbs])
@endpush

@section('content')
<section class="page-hero">
    <div class="wrap">
        <nav class="crumbs" aria-label="breadcrumb">
            <a href="{{ SiteUrl::path('home', $locale) }}">{{ __('marketing.nav.home') }}</a>
            <span aria-hidden="true">/</span>
            <a href="{{ MarketplaceUrl::path('templates', $locale) }}">{{ __('marketplace.site.title') }}</a>
        </nav>
        <p class="eyebrow">{{ __('marketplace.site.designer_eyebrow') }}</p>
        <h1>{{ $name }}</h1>
        @if ($designer->bio($locale))
            <p class="lead">{{ $designer->bio($locale) }}</p>
        @endif
        @if ($designer->portfolio_links)
            <ul class="mk-links">
                @foreach ($designer->portfolio_links as $link)
                    <li><a href="{{ $link }}" rel="nofollow noopener" target="_blank" dir="ltr">{{ preg_replace('#^https?://(www\.)?#', '', rtrim($link, '/')) }}</a></li>
                @endforeach
            </ul>
        @endif
    </div>
</section>

<section class="section" aria-labelledby="designer-templates">
    <div class="wrap">
        <header class="section-head">
            <h2 id="designer-templates">{{ __('marketplace.site.designer_templates', ['name' => $name]) }}</h2>
        </header>
        @if ($cards->isEmpty())
            <p class="muted">{{ __('marketplace.site.empty') }}</p>
        @else
            <ul class="templates mk-grid">
                @foreach ($cards as $card)
                    @include('marketplace.partials.template-card', ['card' => $card, 'eager' => $loop->index < 2])
                @endforeach
            </ul>
        @endif
    </div>
</section>

@include('marketing.partials.cta')
@endsection
