{{-- "Book your invitation with us" (phase 8B, plan section 9 item 9): the venue's reception poster, A4 or A5, Arabic and English. --}}
@extends('pdf.layout')

@section('style')
:root{--c-accent:#5A1A2C;--c-gold:#C8A45D;--c-ink:#2a1219}
body{font-family:"Almarai","DejaVu Sans","Noto Naskh Arabic",sans-serif}
.poster{height:{{ $size === 'A5' ? '210mm' : '297mm' }};padding:{{ $size === 'A5' ? '12mm' : '18mm' }};display:flex;flex-direction:column;align-items:center;justify-content:space-between;text-align:center;
  background:radial-gradient(120mm 90mm at 85% 0%,#f3d5dc 0,transparent 60%),radial-gradient(110mm 90mm at 0% 100%,#f4e6c8 0,transparent 55%),#fbf6f2;position:relative}
.frame{position:absolute;inset:{{ $size === 'A5' ? '6mm' : '9mm' }};border:1.4pt solid var(--c-gold);border-radius:6mm;pointer-events:none}
.top{display:flex;flex-direction:column;align-items:center;gap:3mm}
.logo{width:{{ $size === 'A5' ? '22mm' : '30mm' }};height:{{ $size === 'A5' ? '22mm' : '30mm' }};object-fit:contain;border-radius:4mm;background:#fff}
.venue{font-size:{{ $size === 'A5' ? '15pt' : '20pt' }};color:var(--c-accent);font-weight:700}
.venue-en{font-size:{{ $size === 'A5' ? '10pt' : '12pt' }};color:#6e5a60;direction:ltr}
h1{font-size:{{ $size === 'A5' ? '24pt' : '34pt' }};line-height:1.25;color:var(--c-accent)}
h2{font-size:{{ $size === 'A5' ? '15pt' : '21pt' }};color:#6e5a60;font-weight:700;direction:ltr}
.text{font-size:{{ $size === 'A5' ? '10pt' : '13pt' }};max-width:150mm;color:var(--c-ink)}
.text-en{direction:ltr;color:#6e5a60}
.qr{width:{{ $size === 'A5' ? '70mm' : '100mm' }};height:{{ $size === 'A5' ? '70mm' : '100mm' }};padding:4mm;background:#fff;border-radius:5mm;border:1pt solid #ead9d6}
.qr svg{width:100%;height:100%;display:block}
.url{direction:ltr;font-family:"DejaVu Sans Mono","DejaVu Sans",monospace;font-size:{{ $size === 'A5' ? '9pt' : '11pt' }};color:#6e5a60}
.brand{font-size:{{ $size === 'A5' ? '12pt' : '15pt' }};color:var(--c-gold);font-weight:700}
@endsection

@section('body')
<div class="poster" data-poster="venue">
    <div class="frame"></div>
    <div class="top">
        @if ($logo)
            <img class="logo" src="{{ $logo }}" alt="">
        @endif
        <div class="venue">{{ $nameAr }}</div>
        @if ($nameEn)
            <div class="venue-en">{{ $nameEn }}</div>
        @endif
    </div>
    <div class="top">
        <h1>{{ __('venues.poster.title_ar') }}</h1>
        <h2>{{ __('venues.poster.title_en') }}</h2>
    </div>
    <div class="qr">{!! $qr !!}</div>
    <div class="top">
        <p class="text">{{ __('venues.poster.text_ar') }}</p>
        <p class="text text-en">{{ __('venues.poster.text_en') }}</p>
        <p class="url">{{ $url }}</p>
        <p class="brand">دعوة · Daawa</p>
    </div>
</div>
@endsection
