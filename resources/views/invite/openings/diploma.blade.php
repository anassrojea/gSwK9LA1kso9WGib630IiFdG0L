{{-- Diploma opening: the ribbon slips off, the scroll unrolls between its rods, then caps are thrown (resources/js/openings/diploma.js). --}}
@php
    $hosts = collect($config['sections'])->firstWhere('type', 'hosts');
@endphp
<section class="opening op-diploma" id="opening" aria-label="{{ $occasionLabel }}">
    <div class="stage" data-stage>
        <div class="gdust" data-part="dust" aria-hidden="true"></div>
        <div class="stage-top" data-part="top">
            <span class="label">{{ __('invite.to') }} <span data-guest-name>{{ __('invite.dear_guest') }}</span></span>
            <b>{{ $options['headline'] ?? __('invite.opening_headline.diploma') }}</b>
        </div>
        <div class="dip" data-part="dip">
            <div class="rod" data-part="rod-top"></div>
            <div class="paper" data-part="paper">
                <div class="in" data-part="sheet">
                    <svg class="crest" viewBox="0 0 62 46" aria-hidden="true"><path d="M31 6L4 17l27 11 27-11z" class="cr-top"/><path d="M14 21v10c0 4 8 8 17 8s17-4 17-8V21l-17 7z" class="cr-band"/><path d="M54 18v14" class="cr-cord"/><circle cx="54" cy="33" r="2.5" class="cr-tassel"/></svg>
                    <span class="t">{{ $options['kicker'] ?? $occasionLabel }}</span>
                    <span class="h">{{ __('invite.diploma.title') }}</span>
                    @if ($hosts)
                        <span class="s">{{ $hosts['lines'][0] }}</span>
                    @endif
                    @if ($names)
                        <span class="n">{{ $names['first'] }}@isset($names['second']) &amp; {{ $names['second'] }}@endisset</span>
                        @isset($names['note'])<span class="d">{{ $names['note'] }}</span>@endisset
                    @endif
                    <div class="sig"><span>{{ __('invite.diploma.family') }}</span>@if ($date)<span>{{ $date->short() }}</span>@endif</div>
                </div>
            </div>
            <div class="rod" data-part="rod-bottom"></div>
            <svg class="ribbon" data-part="ribbon" viewBox="0 0 70 110" aria-hidden="true">
                <path d="M24 50l-12 56 14-10 8 14 4-60zM46 50l12 56-14-10-8 14-4-60z" class="rb-tail"/>
                <rect x="0" y="40" width="70" height="14" class="rb-band"/>
                <circle cx="35" cy="47" r="17" class="rb-seal"/><circle cx="35" cy="47" r="12" fill="none" class="rb-ring" stroke-width="1.5"/>
                <path d="M29 47l6-6 6 6-6 6z" class="rb-mark"/>
            </svg>
        </div>
        <div class="caps" data-part="caps" aria-hidden="true"></div>
        <div class="hint" data-part="hint"><i></i>{{ __('invite.scroll_hint.diploma') }}</div>
    </div>
</section>
