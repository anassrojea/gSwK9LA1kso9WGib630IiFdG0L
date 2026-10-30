@extends('marketing.layout')

@php
    use App\Services\Digits;
    use App\Support\SiteUrl;

    $digits = fn ($n) => Digits::localize($n, $locale);
    $comma = $locale === 'ar' ? '، ' : ', ';
    $occasions = array_values(config('marketing.occasions'));
    $description = $profile->description($locale);
    $firstHall = $halls->first();
@endphp

@push('jsonld')
    @include('marketing.partials.jsonld', ['data' => $jsonld])
    @include('marketing.partials.jsonld', ['data' => $breadcrumbs])
@endpush

{{-- "Create your invitation at this venue": a draft with the hall already in the seating planner (POST /create + hall_layout_id) --}}
@php
    $createForm = function (?string $hallId, string $label, string $place, bool $big = false) use ($occasions) {
        return view('marketing.venues.create-form', compact('hallId', 'label', 'place', 'big', 'occasions'))->render();
    };
@endphp

@section('content')
<section class="page-hero venue-hero" data-venue="{{ $profile->slug }}">
    <div class="wrap">
        <nav class="crumbs" aria-label="breadcrumb">
            <a href="{{ SiteUrl::path('home', $locale) }}">{{ __('marketing.nav.home') }}</a>
            <span aria-hidden="true">/</span>
            <a href="{{ SiteUrl::path('venues', $locale) }}">{{ __('venues.index.title') }}</a>
        </nav>
        <div class="venue-head">
            @if ($logoUrl = $profile->logoUrl())
                <img src="{{ $logoUrl }}" alt="" class="venue-logo" width="72" height="72">
            @endif
            <div>
                <h1>{{ $name }}</h1>
                <p class="muted">{{ __("venues.types.{$profile->venue_type}") }} · {{ $city }}@if ($profile->area){{ $comma }}{{ $profile->area }}@endif</p>
            </div>
        </div>
        <ul class="venue-facts">
            @if ($capacity)
                <li>@include('marketing.partials.icon', ['name' => 'seat']) {{ __('venues.show.capacity_value', ['count' => $digits($capacity)]) }}</li>
            @endif
            @if ($halls->isNotEmpty())
                <li>@include('marketing.partials.icon', ['name' => 'check']) {{ __('venues.show.halls') }}: {{ $digits($halls->count()) }}</li>
            @endif
        </ul>
        <div class="actions venue-actions">
            {!! $createForm($firstHall['id'] ?? null, __('venues.show.create'), 'venue_hero', true) !!}
            @if ($whatsapp)
                <a href="{{ $whatsapp }}" class="btn btn-whatsapp" target="_blank" rel="noopener nofollow" data-venue-whatsapp>@include('marketing.partials.icon', ['name' => 'whatsapp']) {{ __('venues.show.whatsapp') }}</a>
            @endif
            @if ($map)
                <a href="{{ $map }}" class="btn btn-ghost" target="_blank" rel="noopener nofollow" data-venue-map>@include('marketing.partials.icon', ['name' => 'link']) {{ __('venues.show.map') }}</a>
            @endif
        </div>
    </div>
</section>

@if ($photos->isNotEmpty())
    <section class="section venue-photos-section" aria-labelledby="photos-title">
        <div class="wrap">
            <h2 id="photos-title" class="sr-only">{{ __('venues.show.photos') }}</h2>
            <ul class="venue-photos" data-venue-photos>
                @foreach ($photos->take(9) as $i => $photo)
                    <li @class(['is-first' => $i === 0])>
                        <img src="{{ $photo->url() }}" alt="{{ $photo->caption ?? $name }}" loading="{{ $i === 0 ? 'eager' : 'lazy' }}" decoding="async" @if ($photo->width) width="{{ $photo->width }}" height="{{ $photo->height }}" @endif>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif

<section class="section section-tint" aria-labelledby="about-title">
    <div class="wrap venue-about">
        <div>
            <h2 id="about-title">{{ __('venues.show.about') }}</h2>
            @if ($description)
                <div class="venue-description">{!! nl2br(e($description)) !!}</div>
            @endif
        </div>
        <aside class="venue-side">
            @if ($amenities)
                <h3>{{ __('venues.show.amenities') }}</h3>
                <ul class="venue-amenities" data-venue-amenities>
                    @foreach ($amenities as $a)
                        <li>@include('marketing.partials.icon', ['name' => 'yes']) {{ __("venues.amenities.{$a}") }}</li>
                    @endforeach
                </ul>
            @endif
            <h3>{{ __('venues.show.location') }}</h3>
            <p>{{ $city }}@if ($profile->area){{ $comma }}{{ $profile->area }}@endif</p>
            @if ($map)
                <p><a href="{{ $map }}" target="_blank" rel="noopener nofollow" class="card-link">{{ __('venues.show.map') }} @include('marketing.partials.icon', ['name' => 'arrow'])</a></p>
            @endif
        </aside>
    </div>
</section>

@if ($halls->isNotEmpty())
    <section class="section" aria-labelledby="halls-title">
        <div class="wrap">
            <header class="section-head">
                <h2 id="halls-title">{{ __('venues.show.halls') }}</h2>
                <p>{{ __('venues.show.create_note') }}</p>
            </header>
            <ul class="venue-halls" data-venue-halls>
                @foreach ($halls as $hall)
                    <li class="venue-hall">
                        <h3>{{ $hall['name'] }}</h3>
                        <p class="muted">{{ __('venues.show.hall_capacity', ['tables' => $digits($hall['tables']), 'seats' => $digits($hall['seats'])]) }}</p>
                        <div class="venue-hall-plan" dir="ltr">
                            @include('seating.hall-svg', ['layout' => $hall['layout']])
                        </div>
                        {!! $createForm($hall['id'], __('venues.show.create_hall'), 'venue_hall') !!}
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif

@include('marketing.partials.cta')
@endsection
