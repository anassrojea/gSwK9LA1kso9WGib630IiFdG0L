{{-- The live photo wall for the hall screen (phase 7B): App\Http\Controllers\Invite\WallController@show,
     driven by resources/js/wall.js (Reverb on public-wall.{slug} + polling). --}}
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('album.wall.title') }} · {{ $couple }}</title>
    @if ($fontsUrl)
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="{{ $fontsUrl }}">
    @endif
    <style>:root{ {!! $themeVars !!} }</style>
    @vite(['resources/css/wall.css', 'resources/js/wall.js'])
</head>
<body>
<div class="wall" data-wall>
    <div class="w-stage" data-stage aria-live="polite">
        <div class="w-empty" data-empty>
            <div class="w-qr big">{!! $qr !!}</div>
            <p>{{ __('album.wall.waiting') }}</p>
        </div>
    </div>

    <header class="w-top">
        <h1>{{ $couple }}</h1>
        <span class="w-live" data-live hidden><i></i>{{ __('album.wall.live') }}</span>
    </header>

    <aside class="w-side" data-side>
        <div class="w-qr">{!! $qr !!}</div>
        <p class="w-scan">{{ __('album.wall.scan') }}</p>
        <p class="w-url" dir="ltr">{{ preg_replace('#^https?://#', '', $uploadUrl) }}</p>
        <p class="w-count" data-count></p>
    </aside>

    <ol class="w-strip" data-strip aria-hidden="true"></ol>
    <button type="button" class="w-fs" data-fullscreen>{{ __('album.wall.fullscreen') }}</button>
</div>
<script type="application/json" id="wall-boot">@json($boot)</script>
</body>
</html>
