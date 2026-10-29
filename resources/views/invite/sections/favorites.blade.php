<section class="blk pad sec-favorites">
    <span class="label rv">{{ $s['label'] ?? __('invite.favorites.label') }}</span>
    @isset($s['title'])<h2 class="rv">{{ $s['title'] }}</h2>@endisset
    <ul class="chips rv">
        @foreach ($s['items'] as $item)
            <li>@isset($item['icon'])<span aria-hidden="true">{{ $item['icon'] }}</span>@endisset{{ $item['text'] }}</li>
        @endforeach
    </ul>
</section>
