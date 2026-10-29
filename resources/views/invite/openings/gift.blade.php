{{-- Gift box opening: the bow flies off, the lid pops, balloons rise and confetti bursts, then the name and age appear (resources/js/openings/gift.js). --}}
@php
    $badge = isset($names['age']) ? \App\Services\Digits::localize($names['age'], $locale) : ($names ? mb_substr($names['first'], 0, 1) : '★');
@endphp
<section class="opening op-gift" id="opening" aria-label="{{ $occasionLabel }}">
    <div class="stage" data-stage>
        <div class="dots" data-part="dots" aria-hidden="true"></div>
        <div class="stage-top" data-part="top">
            <span class="label">{{ __('invite.to') }} <span data-guest-name>{{ __('invite.dear_guest') }}</span></span>
            <b>{{ $options['headline'] ?? __('invite.opening_headline.gift') }}</b>
        </div>
        <div class="gift wiggle" data-part="gift" aria-hidden="true">
            <div class="balloons" data-part="balloons"></div>
            <div class="gbody" data-part="body"></div>
            <div class="glid" data-part="lid"></div>
            <svg class="bow" data-part="bow" viewBox="0 0 100 52"><path d="M50 40C30 10 4 8 8 30s30 14 42 10zM50 40c20-30 46-32 42-10s-30 14-42 10z"/><circle cx="50" cy="40" r="9"/></svg>
            <div class="confetti" data-part="confetti"></div>
        </div>
        <div class="reveal" data-part="reveal">
            <span>{{ $options['kicker'] ?? $occasionLabel }}</span>
            <div class="badge">{{ $badge }}</div>
            @if ($names)
                <b>{{ $names['first'] }}@isset($names['second']) &amp; {{ $names['second'] }}@endisset</b>
            @endif
        </div>
        <div class="hint" data-part="hint"><i></i>{{ __('invite.scroll_hint.gift') }}</div>
    </div>
</section>
