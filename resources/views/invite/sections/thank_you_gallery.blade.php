{{-- v2 thank-you card (phase 8D): the message, album photos picked by the host, and their signature.
     The family's name ("شكراً لعائلة …") comes from /state like every per-guest part of the page. --}}
<section class="blk pad sec-thanks">
    <span class="label rv">{{ $s['label'] ?? __('keepsakes.thank_you.label') }}</span>
    <h2 class="rv">{{ $s['title'] ?? __('keepsakes.thank_you.title') }}</h2>
    <p class="thanks-to rv" data-thanks-to data-template="{{ __('keepsakes.thank_you.to', ['name' => ':name']) }}" hidden></p>
    <p class="thanks-msg rv">{!! nl2br(e($s['message'])) !!}</p>
    @php($photos = collect($s['photos'] ?? [])->map(fn ($p) => $p + ['url' => $mediaUrl($p['src'])])->filter(fn ($p) => $p['url'])->values())
    @if ($photos->isNotEmpty())
        <div class="thanks-gal rv">
            @foreach ($photos as $photo)
                <figure class="ph">
                    <img src="{{ $photo['url'] }}" alt="{{ $photo['alt'] ?? $photo['caption'] ?? '' }}" loading="lazy" decoding="async">
                    @isset($photo['caption'])<figcaption>{{ $photo['caption'] }}</figcaption>@endisset
                </figure>
            @endforeach
        </div>
    @endif
    @if (filled($s['signature'] ?? null))
        <p class="thanks-sign rv">{{ $s['signature'] }}</p>
    @endif
</section>
