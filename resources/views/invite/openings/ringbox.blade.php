{{-- Ring box opening: the lid swings open, the ring rises with a glow, the box drops away and the names appear (resources/js/openings/ringbox.js). --}}
<section class="opening op-ringbox" id="opening" aria-label="{{ $occasionLabel }}">
    <div class="stage" data-stage>
        <div class="stars" data-part="stars" aria-hidden="true"></div>
        <div class="stage-top">
            <span class="label">{{ __('invite.to') }} <span data-guest-name>{{ __('invite.dear_guest') }}</span></span>
            <b>{{ $options['headline'] ?? __('invite.opening_headline.ringbox') }}</b>
        </div>
        <div class="box" aria-hidden="true">
            <div class="glow" data-part="glow"></div>
            <div class="inside" data-part="inside"></div>
            <div class="ring" data-part="ring">
                <svg viewBox="0 0 64 64">
                    <defs><linearGradient id="rb-band" x1="0" x2="1"><stop offset="0" class="rb-dark"/><stop offset=".5" class="rb-light"/><stop offset="1" class="rb-dark"/></linearGradient></defs>
                    <ellipse cx="32" cy="44" rx="19" ry="15" fill="none" stroke="url(#rb-band)" stroke-width="4.5"/>
                    <path d="M32 12l9 10-9 9-9-9z" fill="#fff" stroke="#cfe3f2" stroke-width="1"/>
                    <path d="M23 22h18M32 12v19" stroke="#dbe9f5" stroke-width=".8"/>
                    <path d="M27 30l5 4 5-4" fill="none" stroke="url(#rb-band)" stroke-width="2.5"/>
                    <g data-part="spark" opacity="0" fill="#fff"><path d="M50 8l1.5 4 4 1.5-4 1.5-1.5 4-1.5-4-4-1.5 4-1.5z"/><path d="M12 16l1 2.6 2.6 1-2.6 1-1 2.6-1-2.6-2.6-1 2.6-1z"/></g>
                </svg>
            </div>
            <div class="base" data-part="base"></div>
            <div class="lid" data-part="lid"></div>
        </div>
        <div class="reveal" data-part="reveal">
            <span>{{ $options['kicker'] ?? $occasionLabel }}</span>
            @if ($names)
                <b>{{ $names['first'] }}@isset($names['second']) &amp; {{ $names['second'] }}@endisset</b>
            @endif
            @if ($date)
                <small>{{ $date->short() }}</small>
            @endif
        </div>
        <div class="hint" data-part="hint"><i></i>{{ __('invite.scroll_hint.ringbox') }}</div>
    </div>
</section>
