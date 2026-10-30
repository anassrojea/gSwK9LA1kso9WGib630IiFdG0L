@extends('marketing.layout')

@php
    use App\Support\SiteUrl;
    use App\Services\Site\SiteCatalog;

    $faq = __('marketing.faq.items');
    $hero = SiteCatalog::poster('envelope');
    $demoFor = collect($samples)->keyBy('opening_id');
    $jsonld = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => __('marketing.brand'),
            'url' => SiteUrl::to('home', $locale),
            'inLanguage' => $locale,
            'publisher' => ['@id' => rtrim(config('app.url'), '/').'/#organization'],
        ],
        [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'inLanguage' => $locale,
            'mainEntity' => collect($faq)->map(fn ($item) => [
                '@type' => 'Question',
                'name' => $item['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
            ])->all(),
        ],
    ];
@endphp

@push('jsonld')
    @include('marketing.partials.jsonld', ['data' => $jsonld[0]])
    @include('marketing.partials.jsonld', ['data' => $jsonld[1]])
@endpush

@section('content')
<section class="hero">
    <div class="wrap hero-grid">
        <div class="hero-copy">
            <p class="eyebrow">{{ __('marketing.hero.eyebrow') }}</p>
            <h1>{{ __('marketing.hero.title') }}</h1>
            <p class="lead">{{ __('marketing.hero.text') }}</p>
            <div class="actions">
                <a href="{{ route('create') }}" class="btn btn-primary btn-lg" data-track="cta_create" data-track-place="hero" rel="nofollow">
                    {{ __('marketing.hero.cta') }} @include('marketing.partials.icon', ['name' => 'arrow'])
                </a>
                <a href="#openings" class="btn btn-ghost btn-lg">{{ __('marketing.hero.secondary') }}</a>
            </div>
            <ul class="points">
                @foreach (__('marketing.hero.points') as $point)
                    <li>@include('marketing.partials.icon', ['name' => 'yes']) {{ $point }}</li>
                @endforeach
            </ul>
        </div>
        <div class="hero-visual" aria-hidden="true">
            <div class="phone">
                @if ($hero)
                    <img src="{{ $hero['src'] }}" width="{{ $hero['width'] }}" height="{{ $hero['height'] }}" alt="" fetchpriority="high" decoding="async">
                @else
                    <div class="poster-fallback op-envelope"></div>
                @endif
            </div>
        </div>
    </div>
</section>

<section class="section" id="openings" aria-labelledby="openings-title">
    <div class="wrap">
        <header class="section-head">
            <h2 id="openings-title">{{ __('marketing.openings.title') }}</h2>
            <p>{{ __('marketing.openings.text') }}</p>
        </header>
        <ul class="openings">
            @foreach ($openings as $opening)
                @php
                    $poster = SiteCatalog::poster($opening->id);
                    $openingName = $locale === 'ar' ? $opening->name_ar : $opening->name_en;
                    $demo = $demoFor->get($opening->id);
                @endphp
                <li class="opening-card">
                    <figure class="poster">
                        @if ($poster)
                            <img src="{{ $poster['src'] }}" width="{{ $poster['width'] }}" height="{{ $poster['height'] }}" alt="{{ $openingName }}" loading="lazy" decoding="async">
                        @else
                            <div class="poster-fallback op-{{ $opening->id }}"></div>
                        @endif
                    </figure>
                    <h3>{{ $openingName }}</h3>
                    <p>{{ __("marketing.openings.items.{$opening->id}") }}</p>
                    @if ($demo)
                        <a href="{{ $demo['url'] }}" class="card-link" target="_blank" rel="noopener nofollow">
                            @include('marketing.partials.icon', ['name' => 'play']) {{ __('marketing.openings.try', ['name' => $openingName]) }}
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</section>

<section class="section section-tint" aria-labelledby="steps-title">
    <div class="wrap">
        <header class="section-head">
            <h2 id="steps-title">{{ __('marketing.steps.title') }}</h2>
        </header>
        <ol class="steps">
            @foreach (__('marketing.steps.items') as $i => $step)
                <li>
                    <span class="step-n" aria-hidden="true">{{ \App\Services\Digits::localize($i + 1, $locale) }}</span>
                    <h3>{{ $step['title'] }}</h3>
                    <p>{{ $step['text'] }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>

<section class="section" aria-labelledby="features-title">
    <div class="wrap">
        <header class="section-head">
            <h2 id="features-title">{{ __('marketing.features.title') }}</h2>
        </header>
        <ul class="features">
            @foreach (__('marketing.features.items') as $feature)
                <li>
                    <span class="feature-icon">@include('marketing.partials.icon', ['name' => $feature['icon']])</span>
                    <h3>{{ $feature['title'] }}</h3>
                    <p>{{ $feature['text'] }}</p>
                </li>
            @endforeach
        </ul>
    </div>
</section>

<section class="section section-tint" id="occasions" aria-labelledby="occasions-title">
    <div class="wrap">
        <header class="section-head">
            <h2 id="occasions-title">{{ __('marketing.occasions.title') }}</h2>
        </header>
        <ul class="occasion-links">
            @foreach ($occasions as $slug => $value)
                <li>
                    <a href="{{ SiteUrl::path('occasion', $locale, $slug) }}">
                        <span>{{ __("marketing.occasions.items.{$value}.name") }}</span>
                        @include('marketing.partials.icon', ['name' => 'arrow'])
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</section>

@if ($samples)
    <section class="section" id="samples" aria-labelledby="samples-title">
        <div class="wrap">
            <header class="section-head">
                <h2 id="samples-title">{{ __('marketing.samples.title') }}</h2>
                <p>{{ __('marketing.samples.text') }}</p>
            </header>
            <ul class="samples">
                @foreach ($samples as $sample)
                    <li>
                        <a href="{{ $sample['url'] }}" target="_blank" rel="noopener nofollow" data-track="sample_opened" data-track-occasion="{{ $sample['occasion'] }}">
                            <span class="sample-occasion">{{ __('invite.occasion.'.$sample['occasion']) }}</span>
                            <strong>{{ $sample['name'] }}</strong>
                            <span class="sample-opening">{{ $sample['opening'] }}</span>
                            <span class="sample-open">{{ __('marketing.samples.open') }} @include('marketing.partials.icon', ['name' => 'arrow'])</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif

<section class="section" id="faq" aria-labelledby="faq-title">
    <div class="wrap narrow">
        <header class="section-head">
            <h2 id="faq-title">{{ __('marketing.faq.title') }}</h2>
        </header>
        <div class="faq">
            @foreach ($faq as $item)
                <details>
                    <summary>{{ $item['q'] }}</summary>
                    <p>{{ $item['a'] }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>

@include('marketing.partials.cta')
@endsection
