<section class="blk pad sec-names">
    <h1 class="names rv">
        <span>{{ $s['first'] }}</span>
        @isset($s['second'])
            <span class="amp" aria-hidden="true">&amp;</span>
            <span>{{ $s['second'] }}</span>
        @endisset
    </h1>
    <svg class="rings rv" viewBox="0 0 84 50" aria-hidden="true"><circle cx="32" cy="27" r="18" fill="none" stroke="currentColor" stroke-width="3"/><circle cx="52" cy="27" r="18" fill="none" stroke="currentColor" stroke-width="3"/><path d="M52 4l4 5-4 4-4-4z" fill="currentColor" opacity=".6"/></svg>
    @isset($s['note'])
        <p class="rv narrow">{{ $s['note'] }}</p>
    @endisset
</section>
