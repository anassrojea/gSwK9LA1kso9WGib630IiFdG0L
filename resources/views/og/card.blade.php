{{-- Link preview image (1200 × 630), screenshotted by headless Chromium: App\Services\OgImage\OgImage.
     Self-contained: no network requests are allowed while rendering. --}}
@php
    $long = mb_strlen($couple) > 22;
    $longRecipient = mb_strlen($recipient) > 26;
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<style>
{!! $fontFaces !!}
:root{ {!! $themeVars !!} }
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:1200px;height:630px;overflow:hidden}
body{
    background:var(--c-bg);
    color:var(--c-ink);
    font-family:var(--f-body);
    -webkit-font-smoothing:antialiased;
    text-rendering:optimizeLegibility;
}
.card{
    position:relative;width:1200px;height:630px;
    display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;
    padding:70px 110px;text-align:center;
    background:
        radial-gradient(ellipse 70% 60% at 50% 45%, var(--c-surface) 0%, transparent 70%),
        var(--c-bg);
}
.frame{position:absolute;inset:26px;border:2px solid var(--c-gold);border-radius:calc(var(--radius) + 6px);pointer-events:none}
.frame::after{content:"";position:absolute;inset:10px;border:1px solid color-mix(in srgb, var(--c-gold) 55%, transparent);border-radius:var(--radius)}
.corner{position:absolute;width:46px;height:46px;border-color:var(--c-gold);border-style:solid;border-width:0}
.corner.tl{top:46px;left:46px;border-top-width:4px;border-left-width:4px}
.corner.tr{top:46px;right:46px;border-top-width:4px;border-right-width:4px}
.corner.bl{bottom:46px;left:46px;border-bottom-width:4px;border-left-width:4px}
.corner.br{bottom:46px;right:46px;border-bottom-width:4px;border-right-width:4px}
.occasion{margin-bottom:14px;font-family:var(--f-label);font-size:30px;font-weight:700;color:var(--c-label);letter-spacing:{{ $locale === 'ar' ? '0' : '.18em' }};{{ $locale === 'ar' ? '' : 'text-transform:uppercase;' }}}
.couple{font-family:var(--f-display);font-size:{{ $long ? 84 : 112 }}px;line-height:1.5;margin-block:-10px;color:var(--c-accent);font-weight:700;max-width:980px}
.ornament{display:flex;align-items:center;gap:16px;color:var(--c-gold)}
.ornament i{display:block;width:120px;height:2px;background:currentColor}
.ornament b{display:block;width:12px;height:12px;background:currentColor;transform:rotate(45deg)}
.date{font-size:34px;color:var(--c-soft)}
.ribbon{
    margin-top:12px;padding:16px 44px;border-radius:999px;
    background:var(--c-accent);color:var(--c-on-accent);
    font-family:var(--f-body);font-weight:700;font-size:{{ $longRecipient ? 34 : 42 }}px;line-height:1.3;
    max-width:1000px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    box-shadow:0 10px 30px color-mix(in srgb, var(--c-accent) 30%, transparent);
}
.ribbon small{font-size:.72em;font-weight:400;opacity:.9;margin-inline-end:.45em}
</style>
</head>
<body>
<div class="card">
    <div class="frame"></div>
    <span class="corner tl"></span><span class="corner tr"></span><span class="corner bl"></span><span class="corner br"></span>

    <div class="occasion">{{ $occasion }}</div>
    <div class="couple">{{ $couple }}</div>
    <div class="ornament" aria-hidden="true"><i></i><b></b><i></i></div>
    @if ($date)
        <div class="date">{{ $date }}</div>
    @endif
    <div class="ribbon">@if ($special)<small>{{ $special }}</small>@endif{{ $recipient }}</div>
</div>
</body>
</html>
