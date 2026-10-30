{{-- Highlight video title card (phase 8D), screenshotted by headless Chromium at the video's size:
     App\Services\Keepsakes\TitleCard. Self-contained: no network requests while rendering. --}}
@php
    // 1% of the short side, a little more on a tall (phone) card so the names fill it
    $unit = min($width, $height) / 100 * ($height > $width ? 1.3 : 1);
    $long = mb_strlen($names) > 18;
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<style>
{!! $fontFaces !!}
:root{ {!! $themeVars !!} }
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:{{ $width }}px;height:{{ $height }}px;overflow:hidden}
body{background:var(--c-backdrop, #1B1B1B);color:var(--c-ink);font-family:var(--f-body);-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility}
.card{
    position:relative;width:100%;height:100%;
    display:flex;flex-direction:column;align-items:center;justify-content:center;gap:{{ 2.4 * $unit }}px;
    padding:{{ 10 * $unit }}px;text-align:center;
    background:radial-gradient(ellipse 75% 60% at 50% 45%, var(--c-surface) 0%, var(--c-bg) 72%);
}
.frame{position:absolute;inset:{{ 3.5 * $unit }}px;border:{{ 0.3 * $unit }}px solid var(--c-gold);border-radius:calc(var(--radius) + {{ $unit }}px)}
.frame::after{content:"";position:absolute;inset:{{ 1.2 * $unit }}px;border:1px solid color-mix(in srgb, var(--c-gold) 55%, transparent);border-radius:var(--radius)}
.occasion{font-family:var(--f-label);font-weight:700;font-size:{{ 4.2 * $unit }}px;color:var(--c-label);letter-spacing:{{ $locale === 'ar' ? '0' : '.18em' }};{{ $locale === 'ar' ? '' : 'text-transform:uppercase;' }}}
.names{font-family:var(--f-display);font-weight:700;font-size:{{ ($long ? 9 : 12.5) * $unit }}px;line-height:1.45;color:var(--c-accent);max-width:100%}
.ornament{display:flex;align-items:center;gap:{{ 2 * $unit }}px;color:var(--c-gold)}
.ornament i{display:block;width:{{ 14 * $unit }}px;height:{{ 0.3 * $unit }}px;background:currentColor}
.ornament b{display:block;width:{{ 1.6 * $unit }}px;height:{{ 1.6 * $unit }}px;background:currentColor;transform:rotate(45deg)}
.date{font-size:{{ 4.6 * $unit }}px;color:var(--c-ink)}
.hijri{font-size:{{ 3.6 * $unit }}px;color:var(--c-soft)}
</style>
</head>
<body>
<div class="card">
    <div class="frame"></div>
    <div class="occasion">{{ $occasion }}</div>
    <div class="names">{{ $names }}</div>
    <div class="ornament" aria-hidden="true"><i></i><b></b><i></i></div>
    @if ($date)
        <div class="date">{{ $date }}</div>
    @endif
    @if ($hijri)
        <div class="hijri">{{ $hijri }}</div>
    @endif
</div>
</body>
</html>
