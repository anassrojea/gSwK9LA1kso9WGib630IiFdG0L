@php($style = $s['style'] ?? 'timeline')
<section class="blk pad sec-story story-{{ $style }}" data-drift>
    <span class="label rv">{{ $s['label'] ?? __('invite.story.label') }}</span>
    <h2 class="rv">{{ $s['title'] ?? __('invite.story.title') }}</h2>
    @if ($style === 'messages')
        <div class="messages">
            @foreach ($s['chapters'] as $chapter)
                <div class="msg rv {{ $loop->odd ? 'from' : 'to' }}">
                    @isset($chapter['year'])<span class="yr">{{ \App\Services\Digits::localize($chapter['year'], $locale) }}</span>@endisset
                    <b>{{ $chapter['title'] }}</b>
                    <p>{{ $chapter['text'] }}</p>
                </div>
            @endforeach
        </div>
    @elseif ($style === 'letter')
        <div class="letter-body rv">
            @foreach ($s['chapters'] as $chapter)
                <h3>{{ $chapter['title'] }}</h3>
                <p>{{ $chapter['text'] }}</p>
            @endforeach
        </div>
    @else
        <ol class="chapters">
            @foreach ($s['chapters'] as $chapter)
                <li class="chap rv">
                    <span class="dot" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 21s-7-4.5-9.5-9A5.5 5.5 0 0 1 12 6a5.5 5.5 0 0 1 9.5 6C19 16.5 12 21 12 21z" fill="currentColor"/></svg></span>
                    <div class="box">
                        @isset($chapter['year'])<span class="yr">{{ \App\Services\Digits::localize($chapter['year'], $locale) }}</span>@endisset
                        <h3>{{ $chapter['title'] }}</h3>
                        <p>{{ $chapter['text'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
    @isset($s['quote'])
        <p class="quote rv">{{ $s['quote'] }}</p>
    @endisset
</section>
