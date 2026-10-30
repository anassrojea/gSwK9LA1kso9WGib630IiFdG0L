@extends('marketing.layout')

@php
    use App\Support\SiteUrl;
    use App\Services\Payments\Currencies;
    use App\Services\Entitlements;
    use App\Http\Controllers\Marketing\SiteController;

    // one venue band (venue_pro); the other Venue Pro tiers and yearly rows are on /org/{org}/billing (phase 8A)
    $plans = collect($plans)->filter(fn ($p) => $p['interval'] === null || $p['id'] === 'venue_pro')->values()->all();
    $event = collect($plans)->whereNull('interval')->values();
    $venue = collect($plans)->firstWhere('interval', 'month');
    $features = Entitlements::FEATURES;
    $anyPlaceholder = collect($plans)->contains('placeholder', true) || collect($addons)->contains('placeholder', true);
    $num = fn ($n) => \App\Services\Digits::localize(number_format($n), $locale);
    $planName = fn ($id) => __("marketing.pricing.plans.{$id}.name");
    $offers = collect($plans)->filter(fn ($p) => $p['amount'] !== null)->map(fn ($p) => array_filter([
        '@type' => 'Offer',
        'name' => $planName($p['id']),
        'price' => (string) Currencies::toMajor($p['amount'], $currency),
        'priceCurrency' => $currency,
        'url' => SiteUrl::to('pricing', $locale),
        'availability' => 'https://schema.org/InStock',
        'priceSpecification' => $p['interval'] ? [
            '@type' => 'UnitPriceSpecification',
            'price' => (string) Currencies::toMajor($p['amount'], $currency),
            'priceCurrency' => $currency,
            'billingDuration' => 1,
            'billingIncrement' => 1,
            'unitCode' => 'MON',
        ] : null,
    ]))->values()->all();
    $jsonld = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => __('marketing.brand').' · '.__('marketing.hero.eyebrow'),
            'description' => __('marketing.meta.pricing_description'),
            'brand' => ['@type' => 'Brand', 'name' => 'Daawa'],
            'image' => asset('images/og-site-'.$locale.'.png'),
            'offers' => $offers,
        ],
    ];
@endphp

@push('jsonld')
    @include('marketing.partials.jsonld', ['data' => $jsonld[0]])
@endpush

@section('content')
<section class="page-hero center">
    <div class="wrap narrow">
        <h1>{{ __('marketing.pricing.title') }}</h1>
        <p class="lead">{{ __('marketing.pricing.text') }}</p>
        <nav class="currency-switch" aria-label="{{ __('marketing.pricing.currency') }}">
            @foreach ($currencies as $code)
                <a href="?currency={{ $code }}" rel="nofollow" @if ($code === $currency) aria-current="true" @endif data-currency="{{ $code }}">
                    {{ __("marketing.pricing.currencies.{$code}") }}
                </a>
            @endforeach
        </nav>
    </div>
</section>

<section class="section pt-0" aria-label="{{ __('marketing.nav.pricing') }}">
    <div class="wrap">
        <ul class="plans">
            @foreach ($event as $plan)
                <li class="plan @if ($plan['id'] === 'premium') plan-featured @endif" data-plan="{{ $plan['id'] }}">
                    @if ($plan['id'] === 'premium')
                        <span class="badge">{{ __('marketing.pricing.popular') }}</span>
                    @endif
                    <h2>{{ $planName($plan['id']) }}</h2>
                    <p class="muted">{{ __("marketing.pricing.plans.{$plan['id']}.for") }}</p>
                    <p class="price" data-price>
                        @if ($plan['amount'] === 0)
                            <strong>{{ __('marketing.pricing.free') }}</strong>
                        @elseif ($plan['formatted'])
                            <strong>{{ $plan['formatted'] }}</strong> <span>{{ __('marketing.pricing.per_event') }}</span>
                        @else
                            <span class="muted">{{ __('marketing.pricing.unavailable') }}</span>
                        @endif
                    </p>
                    <ul class="plan-facts">
                        @if ($plan['guests'])
                            <li>@include('marketing.partials.icon', ['name' => 'yes']) {{ __('marketing.pricing.guests', ['count' => $num($plan['guests'])]) }}</li>
                        @endif
                        <li>@include('marketing.partials.icon', ['name' => $plan['storage_days'] ? 'yes' : 'no']) {{ $plan['storage_days'] ? __('marketing.pricing.storage', ['days' => $num($plan['storage_days'])]) : __('marketing.pricing.storage_none') }}</li>
                        @foreach (array_slice($plan['features'], 0, 6) as $feature)
                            <li>@include('marketing.partials.icon', ['name' => 'yes']) {{ __("app.account.features.{$feature}") }}</li>
                        @endforeach
                    </ul>
                    <a href="{{ route('create') }}" class="btn {{ $plan['id'] === 'premium' ? 'btn-primary' : 'btn-ghost' }}" data-track="cta_create" data-track-place="pricing" data-track-plan="{{ $plan['id'] }}" rel="nofollow">{{ __('marketing.pricing.choose') }}</a>
                </li>
            @endforeach
        </ul>

        @if ($venue)
            <div class="venue-band" data-plan="{{ $venue['id'] }}">
                <div>
                    <h2>{{ $planName($venue['id']) }}</h2>
                    <p>{{ __('marketing.pricing.venue_text') }}</p>
                </div>
                <p class="price">
                    @if ($venue['formatted'])
                        <strong>{{ $venue['formatted'] }}</strong> <span>{{ __('marketing.pricing.per_month') }}</span>
                    @endif
                </p>
                @if ($wa = SiteController::whatsappUrl(__('marketing.pricing.plans.venue_pro.name')))
                    <a href="{{ $wa }}" class="btn btn-light" target="_blank" rel="noopener">{{ __('marketing.pricing.venue_cta') }}</a>
                @else
                    <a href="{{ SiteUrl::path('about', $locale) }}" class="btn btn-light">{{ __('marketing.pricing.venue_cta') }}</a>
                @endif
            </div>
        @endif

        <p class="fine">
            {{ __('marketing.pricing.methods') }}
            <a href="{{ SiteUrl::path('legal', $locale, 'refund') }}">{{ __('marketing.pricing.refund_link') }}</a>
            @if ($anyPlaceholder) · <span data-placeholder-note>{{ __('marketing.pricing.placeholder') }}</span> @endif
        </p>
    </div>
</section>

<section class="section section-tint" aria-labelledby="compare-title">
    <div class="wrap">
        <header class="section-head">
            <h2 id="compare-title">{{ __('marketing.pricing.compare') }}</h2>
        </header>
        <div class="table-scroll" tabindex="0" role="region" aria-labelledby="compare-title">
            <table class="compare">
                <thead>
                    <tr>
                        <th scope="col">{{ __('marketing.pricing.feature') }}</th>
                        @foreach ($event as $plan)
                            <th scope="col">{{ $planName($plan['id']) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row">{{ __('marketing.pricing.guests_row') }}</th>
                        @foreach ($event as $plan)
                            <td>{{ $plan['guests'] ? $num($plan['guests']) : '∞' }}</td>
                        @endforeach
                    </tr>
                    <tr>
                        <th scope="row">{{ __('marketing.pricing.storage_row') }}</th>
                        @foreach ($event as $plan)
                            <td>{{ $plan['storage_days'] ? __('marketing.pricing.days', ['days' => $num($plan['storage_days'])]) : '—' }}</td>
                        @endforeach
                    </tr>
                    @foreach ($features as $feature)
                        <tr>
                            <th scope="row">{{ __("app.account.features.{$feature}") }}</th>
                            @foreach ($event as $plan)
                                @php($has = in_array($feature, $plan['features'], true))
                                <td class="{{ $has ? 'yes' : 'no' }}">
                                    @include('marketing.partials.icon', ['name' => $has ? 'yes' : 'no'])
                                    <span class="sr-only">{{ $has ? '✓' : '—' }}</span>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</section>

@if ($addons)
    <section class="section" aria-labelledby="addons-title">
        <div class="wrap">
            <header class="section-head">
                <h2 id="addons-title">{{ __('marketing.pricing.addons') }}</h2>
                <p>{{ __('marketing.pricing.addons_text') }}</p>
            </header>
            <ul class="addons">
                @foreach ($addons as $addon)
                    <li data-addon="{{ $addon['id'] }}">
                        <div>
                            <h3>{{ __("app.checkout.addons.{$addon['id']}.name") }}</h3>
                            <p class="muted">{{ __("app.checkout.addons.{$addon['id']}.text") }}</p>
                        </div>
                        <strong>{{ $addon['formatted'] }}</strong>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif

@include('marketing.partials.cta')
@endsection
