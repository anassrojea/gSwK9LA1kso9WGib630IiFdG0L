@php
    $c = $card['theme']['colors'] ?? [];
    $fonts = $card['theme']['fonts'] ?? [];
@endphp
<li class="template-card mk-card" data-template="{{ $card['id'] }}">
    <figure class="poster mk-poster" style="--bg: {{ $c['bg'] ?? '#fbf6f2' }}; --accent: {{ $c['accent'] ?? '#5a1a2c' }}; --gold: {{ $c['gold'] ?? '#c8a45d' }}; --ink: {{ $c['ink'] ?? '#2a1219' }}; --soft: {{ $c['soft'] ?? '#6e5a60' }}">
        @if ($card['cover'])
            <img src="{{ $card['cover'] }}" alt="{{ $card['name'] }}" loading="{{ ($eager ?? false) ? 'eager' : 'lazy' }}" decoding="async">
        @else
            <div class="mk-poster-art" aria-hidden="true">
                <span class="mk-poster-name" style="font-family: '{{ $fonts['display'] ?? 'Amiri' }}', serif">{{ $card['name'] }}</span>
                <span class="mk-poster-line"></span>
            </div>
        @endif
        @if ($card['price'])
            <span class="tier mk-price">{{ $card['price'] }}</span>
        @endif
    </figure>
    <div class="template-body">
        <h3>{{ $card['name'] }}</h3>
        @if ($card['designer'])
            <p class="muted mk-by">{!! __('marketplace.site.by', ['designer' => '<a href="'.e($card['designer_url']).'">'.e($card['designer']).'</a>']) !!}</p>
        @endif
        <p class="muted mk-meta">{{ __("app.wizard.occasions.{$card['occasion']}") }}@if ($card['opening']){{ $locale === 'ar' ? '، ' : ' · ' }}{{ $card['opening'] }}@endif</p>
        @if ($card['colors'])
            <p class="swatches" aria-hidden="true">
                @foreach ($card['colors'] as $color)
                    <span style="background: {{ $color }}"></span>
                @endforeach
            </p>
        @endif
        @if ($card['signature'])
            <p class="mk-note">{{ __('marketplace.site.in_signature') }}</p>
        @endif
        <form method="post" action="{{ route('create.store') }}">
            @csrf
            <input type="hidden" name="occasion" value="{{ $card['occasion'] }}">
            <input type="hidden" name="template_id" value="{{ $card['id'] }}">
            <button type="submit" class="btn btn-ghost btn-sm" data-track="cta_create" data-track-place="marketplace" data-track-template="{{ $card['id'] }}">{{ __('marketplace.site.use') }}</button>
        </form>
    </div>
</li>
