<section class="blk pad sec-rose">
    <div class="roses rv" data-roses>
        <span class="label">{{ $s['label'] ?? __('invite.rose.label') }}</span>
        <b data-rose-count>{{ \App\Services\Digits::localize($event->rose?->count ?? 0, $locale) }}</b>
        <span>{{ __('invite.rose.count') }}</span>
        <button type="button" class="btn" data-send-rose data-sent="{{ __('invite.rose.sent') }}">{{ __('invite.rose.send') }}</button>
    </div>
</section>
