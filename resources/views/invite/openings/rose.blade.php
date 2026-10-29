{{-- Rose opening: petals rotate from bud to bloom, outer layers first, then petals fall over the names (resources/js/openings/rose.js). --}}
<section class="opening op-rose" id="opening" aria-label="{{ $occasionLabel }}">
    <div class="stage" data-stage>
        <div class="stage-top" data-part="top">
            <span class="label">{{ __('invite.to') }} <span data-guest-name>{{ __('invite.dear_guest') }}</span></span>
            <b>{{ $options['headline'] ?? $occasionLabel }}</b>
        </div>
        <div class="spark" data-part="spark" aria-hidden="true"></div>
        <svg class="rose sway" data-part="rose" viewBox="-160 -200 320 430" aria-hidden="true">
            <defs>
                <linearGradient id="rz-back" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#B8436A"/><stop offset="1" stop-color="#E8839F"/></linearGradient>
                <linearGradient id="rz-mid" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#D9668A"/><stop offset=".7" stop-color="{{ $options['petalColor'] ?? '#F4A6BB' }}"/><stop offset="1" stop-color="#FAD0DC"/></linearGradient>
                <linearGradient id="rz-front" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#E07896"/><stop offset=".6" stop-color="#F6B3C5"/><stop offset="1" stop-color="#FFE3EA"/></linearGradient>
                <linearGradient id="rz-sepal" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#3F6B45"/><stop offset="1" stop-color="#86B184"/></linearGradient>
                <radialGradient id="rz-glow"><stop offset="0" stop-color="#FFF4C9" stop-opacity=".9"/><stop offset="1" stop-color="#FFF4C9" stop-opacity="0"/></radialGradient>
            </defs>
            <circle data-part="glow" cx="0" cy="-60" r="120" fill="url(#rz-glow)" opacity="0"/>
            <path d="M0 0 C 6 60, -10 120, 4 220" fill="none" stroke="#4E7A52" stroke-width="7" stroke-linecap="round"/>
            <path d="M2 110 C 40 80, 80 92, 96 70 C 70 64, 30 70, 2 110Z" fill="#6B9A6A"/>
            <path d="M2 110 C 40 84, 70 86, 96 70" fill="none" stroke="#4E7A52" stroke-width="1.5"/>
            <path d="M0 160 C -36 136, -76 146, -92 124 C -66 116, -28 124, 0 160Z" fill="#6B9A6A"/>
            <path d="M0 160 C -36 140, -64 136, -92 124" fill="none" stroke="#4E7A52" stroke-width="1.5"/>
            <g data-part="head"></g>
        </svg>
        <div class="falling" data-part="falling" aria-hidden="true"></div>
        <div class="reveal" data-part="reveal">
            <span>{{ $occasionLabel }}</span>
            @if ($names)
                <b>{{ $names['first'] }}@isset($names['second']) &amp; {{ $names['second'] }}@endisset</b>
            @endif
            @if ($date)
                <small>{{ $date->short() }}</small>
            @endif
        </div>
        <div class="hint" data-part="hint"><i></i>{{ __('invite.scroll_hint.rose') }}</div>
    </div>
</section>
