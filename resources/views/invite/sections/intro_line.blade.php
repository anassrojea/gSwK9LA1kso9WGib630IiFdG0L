<section class="blk pad sec-intro">
    @if (($s['style'] ?? 'line') === 'verse')
        @include('invite.partials.orn')
        <p class="verse rv">{{ $s['text'] }}@isset($s['caption'])<small>{{ $s['caption'] }}</small>@endisset</p>
    @else
        <p class="line rv">{{ $s['text'] }}</p>
        @isset($s['caption'])<small class="note rv">{{ $s['caption'] }}</small>@endisset
    @endif
</section>
