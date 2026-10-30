@extends('marketing.layout')

@php
    use App\Support\SiteUrl;

    $jsonld = [
        [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => __('marketing.nav.home'), 'item' => SiteUrl::to('home', $locale)],
                ['@type' => 'ListItem', 'position' => 2, 'name' => __("legal.pages.{$page}.title"), 'item' => SiteUrl::to('legal', $locale, $page)],
            ],
        ],
    ];
@endphp

{{-- DRAFT legal texts (resources/content/legal/{ar,en}/*.md): review with a lawyer before launch. --}}
@push('jsonld')
    @include('marketing.partials.jsonld', ['data' => $jsonld[0]])
@endpush

@section('content')
<article class="section legal" data-legal="{{ $page }}">
    <div class="wrap narrow">
        <header class="legal-head">
            <h1>{{ __("legal.pages.{$page}.title") }}</h1>
            <p class="muted">{{ __('legal.updated', ['date' => $updated]) }}</p>
        </header>
        <div class="prose">
            {!! $html !!}
        </div>
        <nav class="legal-nav" aria-label="{{ __('legal.other') }}">
            <h2>{{ __('legal.other') }}</h2>
            <ul>
                @foreach (SiteUrl::LEGAL as $other)
                    @continue($other === $page)
                    <li><a href="{{ SiteUrl::path('legal', $locale, $other) }}">{{ __("legal.pages.{$other}.title") }}</a></li>
                @endforeach
            </ul>
        </nav>
    </div>
</article>
@endsection
