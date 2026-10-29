<section class="blk pad sec-album">
    <span class="label rv">{{ $s['label'] ?? __('invite.album.label') }}</span>
    <h2 class="rv">{{ $s['title'] ?? __('invite.album.title') }}</h2>
    <p class="rv narrow">{{ $s['text'] ?? __('invite.album.text') }}</p>
    <button type="button" class="btn solid rv" disabled aria-describedby="album-soon">{{ __('invite.album.button') }}</button>
    <small class="note rv" id="album-soon">{{ __('invite.album.soon') }}</small>
    @include('invite.partials.orn')
</section>
