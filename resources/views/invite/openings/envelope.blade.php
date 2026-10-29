{{-- Envelope opening: seal fades, flap rotates open, letter rises, envelope drops (resources/js/openings/envelope.js). --}}
@php
    $seal = $options['sealText'] ?? ($names ? mb_substr($names['first'], 0, 1).(isset($names['second']) ? ' '.mb_substr($names['second'], 0, 1) : '') : '♥');
@endphp
<section class="opening op-envelope" id="opening" aria-label="{{ $occasionLabel }}">
    <div class="stage" data-stage>
        <div class="dust" data-part="dust" aria-hidden="true"></div>
        <div class="stage-top">
            <span class="label">{{ $options['kicker'] ?? __('invite.special_invite') }}</span>
            <b>{{ $options['headline'] ?? $occasionLabel }}</b>
        </div>
        <div class="env" data-part="env">
            <div class="back" data-part="back"></div>
            <div class="letter" data-part="letter">
                <div class="in">
                    <span class="lk">{{ $occasionLabel }}</span>
                    @if ($names)
                        <span class="ln">{{ $names['first'] }}@isset($names['second']) <span>&amp;</span> {{ $names['second'] }}@endisset</span>
                    @endif
                    @if ($date)
                        <span class="ld">{{ $date->short() }}</span>
                    @endif
                </div>
            </div>
            <div class="front" data-part="front"></div>
            <div class="to" data-part="to"><small>{{ __('invite.to') }}</small><span data-guest-name>{{ __('invite.dear_guest') }}</span></div>
            <div class="flap" data-part="flap"></div>
            <div class="seal" data-part="seal">{{ $seal }}</div>
        </div>
        <div class="hint" data-part="hint"><i></i>{{ __('invite.scroll_hint.envelope') }}</div>
    </div>
</section>
