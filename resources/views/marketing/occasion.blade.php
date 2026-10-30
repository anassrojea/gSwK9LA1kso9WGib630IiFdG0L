@extends('marketing.layout')

@php
    use App\Support\SiteUrl;
    use App\Services\Site\SiteCatalog;

    $item = __("marketing.occasions.items.{$occasion}");
    $jsonld = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => __('marketing.nav.home'), 'item' => SiteUrl::to('home', $locale)],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $name, 'item' => SiteUrl::to('occasion', $locale, $slug)],
            ],
        ],
    ];
@endphp

@push('jsonld')
    @include('marketing.partials.jsonld', ['data' => $jsonld[0]])
@endpush

@section('content')
<section class="page-hero">
    <div class="wrap">
        <nav class="crumbs" aria-label="breadcrumb">
            <a href="{{ SiteUrl::path('home', $locale) }}">{{ __('marketing.nav.home') }}</a>
            <span aria-hidden="true">/</span>
            <a href="{{ SiteUrl::path('home', $locale) }}#occasions">{{ __('marketing.nav.occasions') }}</a>
        </nav>
        <h1>{{ $item['title'] }}</h1>
        <p class="lead">{{ $item['intro'] }}</p>
        <ul class="points">
            @foreach ($item['points'] as $point)
                <li>@include('marketing.partials.icon', ['name' => 'yes']) {{ $point }}</li>
            @endforeach
        </ul>
        <form method="post" action="{{ route('create.store') }}" class="actions">
            @csrf
            <input type="hidden" name="occasion" value="{{ $occasion }}">
            <button type="submit" class="btn btn-primary btn-lg" data-track="cta_create" data-track-place="occasion" data-track-occasion="{{ $occasion }}">
                {{ __('marketing.occasions.cta', ['occasion' => $name]) }} @include('marketing.partials.icon', ['name' => 'arrow'])
            </button>
        </form>
    </div>
</section>

<section class="section" aria-labelledby="templates-title">
    <div class="wrap">
        <header class="section-head">
            <h2 id="templates-title">{{ __('marketing.occasions.templates', ['occasion' => $name]) }}</h2>
        </header>
        @if ($templates->isEmpty())
            <p class="muted">{{ __('marketing.occasions.no_templates') }}</p>
        @else
            <ul class="templates">
                @foreach ($templates as $template)
                    @php
                        $poster = $template->opening ? SiteCatalog::poster($template->opening->id) : null;
                        $colors = $template->theme?->tokens['colors'] ?? [];
                        $openingName = $template->opening ? ($locale === 'ar' ? $template->opening->name_ar : $template->opening->name_en) : '';
                    @endphp
                    <li class="template-card">
                        <figure class="poster">
                            @if ($poster)
                                <img src="{{ $poster['src'] }}" width="{{ $poster['width'] }}" height="{{ $poster['height'] }}" alt="{{ $openingName }}" loading="{{ $loop->first ? 'eager' : 'lazy' }}" decoding="async">
                            @else
                                <div class="poster-fallback op-{{ $template->opening_id }}"></div>
                            @endif
                            <span class="tier tier-{{ $template->tier }}">{{ __('app.wizard.tiers.'.$template->tier) }}</span>
                        </figure>
                        <div class="template-body">
                            <h3>{{ $locale === 'ar' ? $template->name_ar : $template->name_en }}</h3>
                            @if ($openingName)
                                <p class="muted">{{ __('marketing.occasions.opening', ['name' => $openingName]) }}</p>
                            @endif
                            @if ($colors)
                                <p class="swatches" aria-hidden="true">
                                    @foreach (array_slice(array_values(array_filter($colors, fn ($c) => is_string($c) && preg_match('/^#[0-9a-fA-F]{3,6}$/', $c))), 0, 5) as $color)
                                        <span style="background: {{ $color }}"></span>
                                    @endforeach
                                </p>
                            @endif
                            <form method="post" action="{{ route('create.store') }}">
                                @csrf
                                <input type="hidden" name="occasion" value="{{ $occasion }}">
                                <input type="hidden" name="template_id" value="{{ $template->id }}">
                                <button type="submit" class="btn btn-ghost btn-sm" data-track="cta_create" data-track-place="template" data-track-template="{{ $template->id }}">{{ __('marketing.occasions.use') }}</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>

<section class="section section-tint" aria-labelledby="more-title">
    <div class="wrap">
        <header class="section-head">
            <h2 id="more-title">{{ __('marketing.occasions.title') }}</h2>
        </header>
        <ul class="occasion-links">
            @foreach ($occasions as $otherSlug => $value)
                @continue($otherSlug === $slug)
                <li>
                    <a href="{{ SiteUrl::path('occasion', $locale, $otherSlug) }}">
                        <span>{{ __("marketing.occasions.items.{$value}.name") }}</span>
                        @include('marketing.partials.icon', ['name' => 'arrow'])
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</section>

@include('marketing.partials.cta')
@endsection
