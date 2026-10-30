@extends('marketing.layout')

@php
    use App\Services\Digits;
    use App\Support\SiteUrl;

    $digits = fn ($n) => Digits::localize($n, $locale);
@endphp

@push('jsonld')
    @if ($noindex)
        <meta name="robots" content="noindex, follow">
    @endif
    @include('marketing.partials.jsonld', ['data' => $itemList])
@endpush

@section('content')
<section class="page-hero venues-hero">
    <div class="wrap">
        <nav class="crumbs" aria-label="breadcrumb">
            <a href="{{ SiteUrl::path('home', $locale) }}">{{ __('marketing.nav.home') }}</a>
            <span aria-hidden="true">/</span>
            <span>{{ __('venues.index.title') }}</span>
        </nav>
        <h1>{{ $filters['city'] ? __('venues.meta.city_title', ['city' => __("venues.cities.{$filters['city']}")]) : __('venues.index.title') }}</h1>
        <p class="lead">{{ __('venues.index.subtitle') }}</p>

        <form method="get" action="{{ SiteUrl::path('venues', $locale) }}" class="venue-filters" role="search" data-venue-filters>
            <label class="sr-only" for="vq">{{ __('venues.index.search') }}</label>
            <input id="vq" name="q" type="search" value="{{ $filters['q'] }}" maxlength="60" placeholder="{{ __('venues.index.search') }}" class="venue-input">
            <label class="sr-only" for="vcity">{{ __('venues.dashboard.city') }}</label>
            <select id="vcity" name="city" class="venue-input">
                <option value="">{{ __('venues.index.all_cities') }}</option>
                @foreach ($cities as $c)
                    <option value="{{ $c }}" @selected($filters['city'] === $c)>{{ __("venues.cities.{$c}") }}</option>
                @endforeach
            </select>
            <label class="sr-only" for="vtype">{{ __('venues.dashboard.type') }}</label>
            <select id="vtype" name="type" class="venue-input">
                <option value="">{{ __('venues.index.all_types') }}</option>
                @foreach ($types as $t)
                    <option value="{{ $t }}" @selected($filters['type'] === $t)>{{ __("venues.types.{$t}") }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-primary">{{ __('venues.index.search_button') }}</button>
        </form>
    </div>
</section>

<section class="section" aria-labelledby="venues-count">
    <div class="wrap">
        <p id="venues-count" class="muted venue-count">{{ \App\Services\Venues\VenueDirectory::plural('venues.index.count', $venues->total()) }}</p>
        @if ($cards->isEmpty())
            <p class="venue-empty" data-venues-empty>{{ __('venues.index.empty') }}</p>
        @else
            <ul class="venue-grid" data-venues>
                @foreach ($cards as $card)
                    <li class="venue-card @if ($card['featured']) is-featured @endif">
                        <a href="{{ $card['url'] }}" class="venue-card-link">
                            <div class="venue-cover">
                                @if ($card['image'])
                                    <img src="{{ $card['image'] }}" alt="" loading="lazy" decoding="async">
                                @else
                                    <span class="venue-cover-fallback" aria-hidden="true">{{ mb_substr($card['name'], 0, 1) }}</span>
                                @endif
                                @if ($card['featured'])
                                    <span class="venue-badge">{{ __('venues.index.featured') }}</span>
                                @endif
                            </div>
                            <div class="venue-card-body">
                                <h2>{{ $card['name'] }}</h2>
                                <p class="muted">{{ $card['type'] }} · {{ $card['city'] }}@if ($card['area']){{ $locale === 'ar' ? '، ' : ', ' }}{{ $card['area'] }}@endif</p>
                                @if ($card['capacity'])
                                    <p class="venue-cap">@include('marketing.partials.icon', ['name' => 'seat']) {{ __('venues.index.capacity', ['count' => $digits($card['capacity'])]) }}</p>
                                @endif
                                <span class="card-link">{{ __('venues.index.view') }} @include('marketing.partials.icon', ['name' => 'arrow'])</span>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
            @if ($venues->hasPages())
                <nav class="venue-pages" aria-label="pagination">
                    @if ($venues->previousPageUrl())<a href="{{ $venues->previousPageUrl() }}" rel="prev" class="btn btn-ghost btn-sm">‹</a>@endif
                    <span class="muted">{{ $digits($venues->currentPage()) }} / {{ $digits($venues->lastPage()) }}</span>
                    @if ($venues->nextPageUrl())<a href="{{ $venues->nextPageUrl() }}" rel="next" class="btn btn-ghost btn-sm">›</a>@endif
                </nav>
            @endif
        @endif
    </div>
</section>

@include('marketing.partials.cta')
@endsection
