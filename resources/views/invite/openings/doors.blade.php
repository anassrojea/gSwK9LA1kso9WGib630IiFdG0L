{{-- Arched doors opening: the doors swing inward, a henna pattern draws itself, then we zoom through the arch (resources/js/openings/doors.js). --}}
<section class="opening op-doors" id="opening" aria-label="{{ $occasionLabel }}">
    <div class="stage" data-stage>
        <div class="lanterns" aria-hidden="true">
            @foreach ([[5, 96, 0], [84, 120, -1.2]] as [$x, $len, $delay])
                <svg class="lantern" style="left:{{ $x }}%;animation-delay:{{ $delay }}s" viewBox="0 0 34 {{ $len + 50 }}">
                    <path d="M17 0v{{ $len }}" class="ln-cord"/><path d="M11 {{ $len }}h12l3 6H8z" class="ln-metal"/>
                    <path d="M8 {{ $len + 6 }}h18l-2 30H10z" class="ln-glass"/><path d="M12 {{ $len + 10 }}h10v22H12z" class="ln-light"/>
                    <path d="M10 {{ $len + 36 }}h14l-3 5H13z" class="ln-metal"/>
                </svg>
            @endforeach
        </div>
        <div class="stage-top" data-part="top">
            <span class="label">{{ __('invite.to') }} <span data-guest-name>{{ __('invite.dear_guest') }}</span></span>
            <b>{{ $options['headline'] ?? __('invite.opening_headline.doors') }}</b>
        </div>
        <div class="arch-wrap" data-part="arch">
            <div class="arch-frame"></div>
            <div class="arch">
                <div class="interior">
                    <svg class="mandala" data-part="mandala" viewBox="-100 -100 200 200" aria-hidden="true"></svg>
                    <div class="txt" data-part="txt">
                        <span>{{ $options['kicker'] ?? $occasionLabel }}</span>
                        @if ($names)
                            <b>{{ $names['first'] }}@isset($names['second']) &amp; {{ $names['second'] }}@endisset</b>
                        @endif
                    </div>
                </div>
                {{-- mirror-symmetric, so physical left/right is correct in RTL and LTR alike --}}
                <div class="door l" data-part="door-l"><span class="knob"></span></div>
                <div class="door r" data-part="door-r"><span class="knob"></span></div>
            </div>
        </div>
        <div class="hint" data-part="hint"><i></i>{{ __('invite.scroll_hint.doors') }}</div>
    </div>
</section>
