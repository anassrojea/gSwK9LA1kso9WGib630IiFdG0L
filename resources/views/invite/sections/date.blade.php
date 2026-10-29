@php($d = \App\View\InviteDate::fromIso($s['start'], $locale))
<section class="blk pad sec-date">
    <span class="label rv">{{ $s['label'] ?? __('invite.date.label') }}</span>
    <div class="date-card rv">
        <div class="date-row">
            <span class="side">{{ $d->weekday() }}</span>
            <span class="day">{{ $d->day() }}</span>
            <span class="side">{{ $d->time() }}</span>
        </div>
        <div class="month">{{ $d->monthYear() }}</div>
        @if ($s['showHijri'] ?? false)
            <div class="hijri">{{ __('invite.date.hijri', ['date' => $s['hijriOverride'] ?? $d->hijri()]) }}</div>
        @endif
    </div>
    @if ($s['countdown'] ?? false)
        <div class="countdown rv" data-countdown="{{ $d->at->toIso8601String() }}" role="timer" aria-label="{{ __('invite.date.countdown') }}">
            <div><b data-cd="d">{{ \App\Services\Digits::localize(0, $locale) }}</b><span>{{ __('invite.date.days') }}</span></div>
            <div><b data-cd="h">{{ \App\Services\Digits::localize(0, $locale) }}</b><span>{{ __('invite.date.hours') }}</span></div>
            <div><b data-cd="m">{{ \App\Services\Digits::localize(0, $locale) }}</b><span>{{ __('invite.date.minutes') }}</span></div>
            <div><b data-cd="s">{{ \App\Services\Digits::localize(0, $locale) }}</b><span>{{ __('invite.date.seconds') }}</span></div>
        </div>
    @endif
</section>
