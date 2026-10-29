<section class="blk pad sec-wishes alt">
    <span class="label rv">{{ $s['label'] ?? __('invite.wishes.label') }}</span>
    @isset($s['title'])<h2 class="rv">{{ $s['title'] }}</h2>@endisset
    <div class="wishes">
        @foreach ($s['items'] as $item)
            <blockquote class="wish rv">
                <p>{{ $item['text'] }}</p>
                @isset($item['name'])<cite>{{ $item['name'] }}</cite>@endisset
            </blockquote>
        @endforeach
    </div>
</section>
