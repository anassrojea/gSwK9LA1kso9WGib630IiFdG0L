{{-- Shared shell of the printables (App\Http\Controllers\Dashboard\SeatingPdfController), printed by
     headless Chromium with every network request blocked: fonts come inlined in $fontFaces. --}}
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>{{ $event->title }}</title>
<style>
{!! $fontFaces !!}
:root{ --c-bg:#fff;--c-ink:#1f1a1c;--c-accent:#5A1A2C;--c-gold:#C8A45D;--c-soft:#6b6064;--f-display:serif;--f-body:serif;--f-label:sans-serif; {!! $themeVars !!} }
@page{ size: {{ $page ?? 'A4' }}; margin: {{ $margin ?? '12mm' }}; }
*{box-sizing:border-box;margin:0;padding:0}
html{-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{font-family:var(--f-body),"DejaVu Sans","Noto Naskh Arabic",sans-serif;color:var(--c-ink);font-size:11pt;line-height:1.5;background:#fff}
.latin{font-family:var(--f-label),"DejaVu Sans",sans-serif;direction:ltr;unicode-bidi:isolate}
.muted{color:var(--c-soft)}
.head{display:flex;justify-content:space-between;align-items:flex-end;gap:12mm;border-bottom:1.5pt solid var(--c-gold);padding-bottom:3mm;margin-bottom:5mm}
.head h1{font-family:var(--f-display),serif;color:var(--c-accent);font-size:20pt;line-height:1.3}
.head .sub{font-size:10pt}
.page-break{break-before:page}
@yield('style')
</style>
</head>
<body>
@yield('body')
</body>
</html>
