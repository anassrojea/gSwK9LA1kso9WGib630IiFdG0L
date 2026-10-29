<section class="blk pad sec-program">
    <span class="label rv">{{ $s['label'] ?? __('invite.program.label') }}</span>
    <h2 class="rv">{{ $s['title'] ?? __('invite.program.title') }}</h2>
    <dl class="prog rv">
        @foreach ($s['items'] as $item)
            <div><dt>{{ \App\Services\Digits::time($item['time'], $locale) }}</dt><dd>{{ $item['label'] }}</dd></div>
        @endforeach
    </dl>
</section>
