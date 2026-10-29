{{-- Fallback for openings that are not ported yet: a still cover, no script. --}}
<section class="opening op-cover" aria-label="{{ $occasionLabel }}">
    <div class="stage">
        <div class="cover">
            <span class="label">{{ $occasionLabel }}</span>
            @if ($names)
                <b>{{ $names['first'] }}@isset($names['second']) <span>&amp;</span> {{ $names['second'] }}@endisset</b>
            @endif
            @if ($date)
                <small>{{ $date->short() }}</small>
            @endif
            <span class="to">{{ __('invite.to') }} <span data-guest-name>{{ __('invite.dear_guest') }}</span></span>
        </div>
    </div>
</section>
