<section class="blk pad sec-dress">
    <span class="label rv">{{ $s['label'] ?? __('invite.dress_code.label') }}</span>
    <div class="palette rv" role="list" aria-label="{{ __('invite.dress_code.palette') }}">
        @foreach ($s['colors'] as $color)
            <i role="listitem" style="background:{{ $color }}" title="{{ $color }}"></i>
        @endforeach
    </div>
    @isset($s['note'])<p class="note rv">{{ $s['note'] }}</p>@endisset
</section>
