@php
    $locale = $config['locale'];
    $openingId = $config['opening']['id'];
    $names = collect($config['sections'])->firstWhere('type', 'names');
    $dateSection = collect($config['sections'])->firstWhere('type', 'date');
    $date = $dateSection ? \App\View\InviteDate::fromIso($dateSection['start'], $locale) : null;
    $occasionLabel = __('invite.occasion.'.$config['occasion']);
    $coupleNames = $names ? trim($names['first'].(isset($names['second']) ? ($locale === 'ar' ? ' و' : ' & ').$names['second'] : '')) : $event->title;
    $title = $occasionLabel.' '.$coupleNames;
    $hasOpening = view()->exists('invite.openings.'.$openingId);
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $date ? $date->weekday().' '.$date->day().' '.$date->monthYear() : $occasionLabel }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ __('invite.special_invite') }}{{ $date ? ' · '.$date->day().' '.$date->monthYear() : '' }}">
    <meta property="og:locale" content="{{ $locale === 'ar' ? 'ar_AR' : 'en_US' }}">
    @if ($ogImage ?? null)
        {{-- per-token preview card ("دعوة خاصة لعائلة …"), rendered by headless Chromium: App\Services\OgImage\OgImage --}}
        <meta property="og:image" content="{{ $ogImage }}">
        <meta property="og:image:type" content="image/png">
        <meta property="og:image:width" content="{{ \App\Services\OgImage\OgImage::WIDTH }}">
        <meta property="og:image:height" content="{{ \App\Services\OgImage\OgImage::HEIGHT }}">
        <meta property="og:image:alt" content="{{ $title }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="{{ $ogImage }}">
    @endif
    <meta name="theme-color" content="{{ $config['theme']['colors']['backdrop'] ?? $config['theme']['colors']['accent'] }}">
    @if ($fontsUrl)
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="{{ $fontsUrl }}">
    @endif
    <style>:root{ {!! $themeVars !!} }</style>
    @vite(['resources/css/invite.css', 'resources/js/invite.js'])
</head>
<body>
<div class="app" data-opening="{{ $openingId }}" data-occasion="{{ $config['occasion'] }}">

    @if ($hasOpening)
        @include('invite.openings.'.$openingId, [
            'options' => $config['opening']['options'] ?? [],
            'names' => $names,
            'date' => $date,
            'occasionLabel' => $occasionLabel,
        ])
    @else
        @include('invite.openings.cover', ['names' => $names, 'date' => $date, 'occasionLabel' => $occasionLabel])
    @endif

    @php($cover = $config['media']['cover'] ?? null)
    @if ($cover && ($coverSrc = $mediaUrl($cover['src'])))
        <figure class="cover-media">
            @if ($cover['type'] === 'video')
                <video src="{{ $coverSrc }}" @if ($poster = $mediaUrl($cover['poster'] ?? null)) poster="{{ $poster }}" @endif
                       muted playsinline loop autoplay preload="metadata" aria-label="{{ $title }}"></video>
            @else
                <img src="{{ $coverSrc }}" alt="{{ $title }}" loading="lazy" decoding="async">
            @endif
        </figure>
    @endif

    <main>
        @include('invite.partials.live-day')
        @foreach ($sections as $i => $s)
            @includeIf('invite.sections.'.$s['type'], [
                's' => $s,
                'i' => $i,
                'date' => $date,
                'locale' => $locale,
                'title' => $title,
            ])
        @endforeach
    </main>

    <footer class="foot">
        @if ($showBadge)
            <span>{{ __('invite.footer') }}</span>
        @endif
        <a href="{{ url('/') }}" rel="noopener">{{ __('invite.make_yours') }}</a>
    </footer>

    @php($music = ($config['effects']['music'] ?? false) ? ($config['media']['music'] ?? null) : null)
    @if ($music && ($musicSrc = $mediaUrl($music['src'])))
        {{-- never autoplays: browsers block it and guests may be somewhere quiet --}}
        <button type="button" class="music-toggle" data-music="{{ $musicSrc }}" aria-pressed="false"
                aria-label="{{ __('invite.music.play') }}" data-label-play="{{ __('invite.music.play') }}" data-label-pause="{{ __('invite.music.pause') }}">♪</button>
    @endif

    <div class="toast" id="toast" role="status" aria-live="polite"></div>
</div>
<script type="application/json" id="invite-boot">@json($boot)</script>
</body>
</html>
