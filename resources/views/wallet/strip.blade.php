{{-- A wallet pass's strip image (750 × 246, Apple's @2x size), screenshotted by headless Chromium:
     App\Services\Wallet\PassImages. Self-contained: no network requests are allowed while rendering. --}}
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<style>
{!! $fontFaces !!}
:root{ {!! $themeVars !!} }
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:{{ $width }}px;height:{{ $height }}px;overflow:hidden}
body{
    display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;text-align:center;
    background:
        radial-gradient(ellipse 70% 80% at 50% 50%, color-mix(in srgb, var(--c-gold) 22%, transparent) 0%, transparent 70%),
        var(--c-accent);
    color:var(--c-on-accent);
    font-family:var(--f-body);
    -webkit-font-smoothing:antialiased;
}
.frame{position:absolute;inset:16px;border:2px solid color-mix(in srgb, var(--c-gold) 80%, white);border-radius:calc(var(--radius) + 4px)}
.occasion{font-family:var(--f-label);font-size:24px;font-weight:700;color:color-mix(in srgb, var(--c-gold) 70%, white);letter-spacing:{{ $locale === 'ar' ? '0' : '.16em' }};{{ $locale === 'ar' ? '' : 'text-transform:uppercase;' }}}
.couple{font-family:var(--f-display);font-size:{{ mb_strlen($couple) > 22 ? 52 : 68 }}px;line-height:1.35;font-weight:700;max-width:680px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.orn{display:flex;align-items:center;gap:10px;color:color-mix(in srgb, var(--c-gold) 80%, white)}
.orn i{display:block;width:70px;height:2px;background:currentColor}
.orn b{display:block;width:9px;height:9px;background:currentColor;transform:rotate(45deg)}
</style>
</head>
<body>
<div class="frame"></div>
<div class="occasion">{{ $occasion }}</div>
<div class="couple">{{ $couple }}</div>
<div class="orn" aria-hidden="true"><i></i><b></b><i></i></div>
</body>
</html>
