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

    <main>
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

    <div class="toast" id="toast" role="status" aria-live="polite"></div>
</div>
<script type="application/json" id="invite-boot">@json($boot)</script>
</body>
</html>
