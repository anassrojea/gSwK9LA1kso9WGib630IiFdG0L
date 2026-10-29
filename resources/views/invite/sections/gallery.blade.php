<section class="blk pad sec-gallery">
    <span class="label rv">{{ $s['label'] ?? __('invite.gallery.label') }}</span>
    <h2 class="rv">{{ $s['title'] ?? __('invite.gallery.title') }}</h2>
    <div class="gal rv">
        @foreach ($s['photos'] as $photo)
            @if ($src = $mediaUrl($photo['src']))
                <figure class="ph">
                    <img src="{{ $src }}" alt="{{ $photo['alt'] ?? $photo['caption'] ?? '' }}" loading="lazy" decoding="async">
                    @isset($photo['caption'])<figcaption>{{ $photo['caption'] }}</figcaption>@endisset
                </figure>
            @endif
        @endforeach
    </div>
</section>
