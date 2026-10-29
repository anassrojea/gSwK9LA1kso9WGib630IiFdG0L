<section class="blk pad sec-hosts">
    <div class="hosts rv">
        <span class="label">{{ $s['label'] ?? __('invite.hosts.label') }}</span>
        @foreach ($s['lines'] as $line)
            <b class="host">{{ $line }}</b>
        @endforeach
        <span>{{ $s['outro'] ?? __('invite.hosts.outro') }}</span>
    </div>
</section>
