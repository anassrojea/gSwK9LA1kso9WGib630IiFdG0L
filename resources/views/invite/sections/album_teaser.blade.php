@php
    // the album (phase 7B): open once the party starts, or earlier when the host allows it (App\Services\Album\Album)
    $albumService = app(\App\Services\Album\Album::class);
    $albumToken = request()->route('token');
    $albumOn = $albumToken && $albumService->enabled($event);
    $albumOpen = $albumOn && $albumService->uploadsOpen($event);
@endphp
<section class="blk pad sec-album">
    <span class="label rv">{{ $s['label'] ?? __('invite.album.label') }}</span>
    <h2 class="rv">{{ $s['title'] ?? __('invite.album.title') }}</h2>
    <p class="rv narrow">{{ $s['text'] ?? __('invite.album.text') }}</p>
    @if ($albumOpen)
        <a class="btn solid rv" href="{{ route('album.show', $albumToken, false) }}" data-album-link>{{ __('invite.album.button') }}</a>
    @elseif ($albumOn)
        <a class="btn rv" href="{{ route('album.show', $albumToken, false) }}" data-album-link>{{ __('invite.album.view') }}</a>
        <small class="note rv">{{ __('invite.album.opens_at_party') }}</small>
    @else
        <button type="button" class="btn solid rv" disabled aria-describedby="album-soon">{{ __('invite.album.button') }}</button>
        <small class="note rv" id="album-soon">{{ __('invite.album.soon') }}</small>
    @endif
    @include('invite.partials.orn')
</section>
