{{-- "Find your table" at the entrance (phase 8B, plan section 9 item 2): one event's QR to /e/{slug}/find, A4 or A5, Arabic and English. --}}
@extends('pdf.layout')

@section('style')
.poster{height:{{ $size === 'A5' ? '210mm' : '297mm' }};padding:{{ $size === 'A5' ? '12mm' : '18mm' }};display:flex;flex-direction:column;align-items:center;justify-content:space-between;text-align:center;position:relative;
  background:linear-gradient(180deg,color-mix(in srgb,var(--c-gold) 16%,#fff) 0,#fff 45%)}
.frame{position:absolute;inset:{{ $size === 'A5' ? '6mm' : '9mm' }};border:1.4pt solid var(--c-gold);border-radius:6mm}
.event{font-family:var(--f-display),serif;font-size:{{ $size === 'A5' ? '18pt' : '26pt' }};color:var(--c-accent);line-height:1.3}
h1{font-size:{{ $size === 'A5' ? '28pt' : '40pt' }};color:var(--c-accent);line-height:1.2}
h2{font-size:{{ $size === 'A5' ? '15pt' : '22pt' }};color:var(--c-soft);direction:ltr;font-family:var(--f-label),"DejaVu Sans",sans-serif}
.text{font-size:{{ $size === 'A5' ? '10.5pt' : '14pt' }};max-width:150mm}
.text-en{direction:ltr;color:var(--c-soft);font-family:var(--f-label),"DejaVu Sans",sans-serif}
.qr{width:{{ $size === 'A5' ? '75mm' : '110mm' }};height:{{ $size === 'A5' ? '75mm' : '110mm' }};padding:4mm;background:#fff;border-radius:5mm;border:1pt solid #e6dde0}
.qr svg{width:100%;height:100%;display:block}
.url{direction:ltr;font-family:"DejaVu Sans Mono","DejaVu Sans",monospace;font-size:{{ $size === 'A5' ? '9pt' : '11pt' }};color:var(--c-soft)}
.group{display:flex;flex-direction:column;align-items:center;gap:2.5mm}
@endsection

@section('body')
<div class="poster" data-poster="find">
    <div class="frame"></div>
    <div class="group">
        <div class="event">{{ $event->title }}</div>
    </div>
    <div class="group">
        <h1>{{ __('venues.find.poster_title_ar') }}</h1>
        <h2>{{ __('venues.find.poster_title_en') }}</h2>
    </div>
    <div class="qr">{!! $qr !!}</div>
    <div class="group">
        <p class="text">{{ __('venues.find.poster_text_ar') }}</p>
        <p class="text text-en">{{ __('venues.find.poster_text_en') }}</p>
        <p class="url">{{ $url }}</p>
    </div>
</div>
@endsection
