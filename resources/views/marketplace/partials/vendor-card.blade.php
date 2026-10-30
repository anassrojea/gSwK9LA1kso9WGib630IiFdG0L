@php
    use App\Services\Marketplace\MarketplaceUrl;
    $photo = $listing->photoUrls()[0] ?? null;
@endphp
<li class="mk-vendor @if ($listing->featured) is-featured @endif" data-vendor="{{ $listing->slug }}">
    <a href="{{ MarketplaceUrl::path('vendor', $locale, $listing->slug) }}" class="mk-vendor-link">
        <figure class="mk-vendor-photo">
            @if ($photo)
                <img src="{{ $photo }}" alt="{{ $listing->name($locale) }}" loading="lazy" decoding="async">
            @else
                <span class="mk-vendor-initial" aria-hidden="true">{{ mb_substr($listing->name($locale), 0, 1) }}</span>
            @endif
            @if ($listing->featured)
                <span class="tier mk-featured">{{ __('vendors.public.featured') }}</span>
            @endif
        </figure>
        <div class="mk-vendor-body">
            <span class="sample-occasion">{{ __("vendors.categories.{$listing->category}") }}</span>
            <h3>{{ $listing->name($locale) }}</h3>
            <p class="muted">{{ collect($listing->cities ?? [])->map(fn ($c) => __("vendors.cities.{$c}"))->implode($locale === 'ar' ? '، ' : ', ') }}</p>
        </div>
    </a>
</li>
