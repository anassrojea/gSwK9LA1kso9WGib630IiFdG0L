{{-- The printed photo book (phase 8D), printed to PDF by headless Chromium: App\Services\Keepsakes\PhotoBookPdf.
     Each page = trim + bleed + slug (crop marks); photos fill to the bleed edge. All sizes in mm. --}}
@php
    $mm = fn ($v) => rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.').'mm';
    $box = $g['slug'];                      // where the bleed box starts
    $trim = $g['slug'] + $g['bleed'];       // where the trim box starts
    $safe = 12;                             // text stays this far inside the trim
@endphp
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<title>{{ $book->title }}</title>
<style>
{!! $fontFaces !!}
:root{ {!! $themeVars !!} }
@page{size:{{ $mm($g['page_w']) }} {{ $mm($g['page_h']) }};margin:0}
*{box-sizing:border-box;margin:0;padding:0}
html,body{background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{font-family:var(--f-body, serif);color:var(--c-ink, #222)}
.page{position:relative;width:{{ $mm($g['page_w']) }};height:{{ $mm($g['page_h']) }};overflow:hidden;break-after:page;page-break-after:always}
.page:last-child{break-after:auto;page-break-after:auto}
.bleed{position:absolute;left:{{ $mm($box) }};top:{{ $mm($box) }};width:{{ $mm($g['trim_w'] + 2 * $g['bleed']) }};height:{{ $mm($g['trim_h'] + 2 * $g['bleed']) }};overflow:hidden;background:var(--c-bg, #fbf6f2)}
.bleed img{display:block;width:100%;height:100%;object-fit:cover}
.double{display:grid;grid-template-columns:1fr 1fr;gap:4mm;background:var(--c-bg, #fbf6f2)}
.double img{height:100%}
.cover .shade{position:absolute;inset:0;background:linear-gradient(180deg, transparent 45%, rgb(0 0 0 / .55))}
.cover .words{position:absolute;inset-inline:{{ $mm($g['bleed'] + $safe) }};bottom:{{ $mm($g['bleed'] + $safe) }};color:#fff;text-align:center;display:grid;gap:3mm}
.cover h1{font-family:var(--f-display, serif);font-size:20mm;line-height:1.35;font-weight:700}
.cover p{font-family:var(--f-label, sans-serif);font-size:6mm}
.text,.back{display:grid;place-content:center;gap:5mm;text-align:center;padding:{{ $mm($g['bleed'] + $safe) }}}
.text .orn{color:var(--c-gold, #c9a35c);font-size:14mm;line-height:1}
.text h2{font-family:var(--f-display, serif);font-size:13mm;color:var(--c-accent, #5a1a2c)}
.back p{font-family:var(--f-label, sans-serif);font-size:4.5mm;color:var(--c-soft, #866)}
.back h2{font-family:var(--f-display, serif);font-size:11mm;color:var(--c-accent, #5a1a2c)}
.folio{position:absolute;bottom:{{ $mm($trim + 5) }};inset-inline-start:{{ $mm($trim + 6) }};font:3mm var(--f-label, sans-serif);color:rgb(255 255 255 / .85);text-shadow:0 0 1mm rgb(0 0 0 / .6)}
/* crop marks: in the slug, on the trim lines, never inside the bleed */
.mark{position:absolute;background:#000}
.mark.h{height:.25mm;width:{{ $mm(max(0, $g['slug'] - 1)) }}}
.mark.v{width:.25mm;height:{{ $mm(max(0, $g['slug'] - 1)) }}}
</style>
</head>
<body>
@foreach ($layout as $p => $page)
    <section class="page" data-kind="{{ $page['kind'] }}">
        <div class="bleed {{ $page['kind'] }}">
            @switch($page['kind'])
                @case('cover')
                    @if ($src = $files[$p][0] ?? null)<img src="{{ $src }}" alt="">@endif
                    <div class="shade"></div>
                    <div class="words">
                        <h1>{{ $book->title }}</h1>
                        @if ($book->subtitle)<p>{{ $book->subtitle }}</p>@endif
                        @if ($date)<p>{{ $date }}</p>@endif
                    </div>
                    @break
                @case('single')
                    @if ($src = $files[$p][0] ?? null)<img src="{{ $src }}" alt="">@endif
                    @break
                @case('double')
                    @foreach ($page['photos'] as $i => $photo)
                        @if ($src = $files[$p][$i] ?? null)<img src="{{ $src }}" alt="">@endif
                    @endforeach
                    @break
                @case('text')
                    <div class="orn" aria-hidden="true">❦</div>
                    <h2>{{ $book->title }}</h2>
                    @break
                @default
                    <h2>{{ $book->title }}</h2>
                    @if ($date)<p>{{ $occasion }} · {{ $date }}</p>@endif
                    <p>{{ __('keepsakes.photo_book.made_with', [], $locale) }}</p>
            @endswitch
        </div>
        @if (! in_array($page['kind'], ['cover', 'back'], true))
            <span class="folio">{{ \App\Services\Digits::localize($p + 1, $locale) }}</span>
        @endif
        @if ($g['marks'])
            @php($r = $g['page_w'] - $trim)
            @php($b = $g['page_h'] - $trim)
            <i class="mark h" style="left:0;top:{{ $mm($trim) }}"></i>
            <i class="mark h" style="left:0;top:{{ $mm($b) }}"></i>
            <i class="mark h" style="right:0;top:{{ $mm($trim) }}"></i>
            <i class="mark h" style="right:0;top:{{ $mm($b) }}"></i>
            <i class="mark v" style="top:0;left:{{ $mm($trim) }}"></i>
            <i class="mark v" style="top:0;left:{{ $mm($r) }}"></i>
            <i class="mark v" style="bottom:0;left:{{ $mm($trim) }}"></i>
            <i class="mark v" style="bottom:0;left:{{ $mm($r) }}"></i>
        @endif
    </section>
@endforeach
</body>
</html>
