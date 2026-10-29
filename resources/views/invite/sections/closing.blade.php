<section class="blk pad sec-closing">
    @include('invite.partials.orn')
    @isset($s['label'])<span class="label rv">{{ $s['label'] }}</span>@endisset
    @isset($s['title'])<h2 class="rv">{{ $s['title'] }}</h2>@endisset
    <p class="quote rv">{{ $s['text'] }}</p>
</section>
