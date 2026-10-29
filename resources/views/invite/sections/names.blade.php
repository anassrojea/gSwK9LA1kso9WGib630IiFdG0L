<section class="blk pad sec-names">
    <h1 class="names rv">
        <span>{{ $s['first'] }}</span>
        @isset($s['second'])
            <span class="amp" aria-hidden="true">&amp;</span>
            <span>{{ $s['second'] }}</span>
        @endisset
    </h1>
    @isset($s['age'])
        <p class="age rv">{{ trans_choice('invite.names.age', $s['age'], ['age' => \App\Services\Digits::localize($s['age'], $locale)]) }}</p>
    @endisset
    @isset($s['second'])
        <svg class="rings rv" viewBox="0 0 84 50" aria-hidden="true"><circle cx="32" cy="27" r="18" fill="none" stroke="currentColor" stroke-width="3"/><circle cx="52" cy="27" r="18" fill="none" stroke="currentColor" stroke-width="3"/><path d="M52 4l4 5-4 4-4-4z" fill="currentColor" opacity=".6"/></svg>
    @else
        {{-- one honoree (birthday, graduation, henna…): a plain ornament instead of wedding rings --}}
        @include('invite.partials.orn')
    @endisset
    @isset($s['note'])
        <p class="rv narrow">{{ $s['note'] }}</p>
    @endisset
</section>
