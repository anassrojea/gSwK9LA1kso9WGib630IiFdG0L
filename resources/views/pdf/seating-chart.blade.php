{{-- Seating chart (plan sections 4.2 and 7.3): the hall with table numbers, then who sits at each table. --}}
@extends('pdf.layout', ['page' => 'A4 landscape', 'margin' => '10mm'])

@section('style')
.hall{display:block;width:100%;height:150mm}
.h-floor{fill:#fff;stroke:#d9cfd2;stroke-width:1.5}
.h-section{fill:none;stroke:#e6dde0;stroke-dasharray:6 5;stroke-width:1.2}
.h-section-label{font-size:12px;fill:var(--c-soft);font-family:var(--f-label),"DejaVu Sans",sans-serif}
.h-stage{fill:color-mix(in srgb,var(--c-gold) 22%,#fff);stroke:var(--c-gold);stroke-width:1.2}
.h-stage-label{font-size:13px;fill:var(--c-accent);font-weight:700}
.h-dance{fill:none;stroke:#e0d6d9;stroke-dasharray:3 4}
.h-entrance{fill:var(--c-gold)}
.h-chair{fill:#fff;stroke:#c9bcc1;stroke-width:1}
.h-chair.is-taken{fill:var(--c-accent);stroke:var(--c-accent)}
.h-top{fill:#fbf8f6;stroke:#b9a9ae;stroke-width:1.2}
.is-used .h-top{fill:color-mix(in srgb,var(--c-gold) 18%,#fff);stroke:var(--c-gold)}
.h-num{font-weight:700;fill:var(--c-ink);font-family:"DejaVu Sans",sans-serif}
.h-vip{stroke:var(--c-gold)}
.h-sofa-back{fill:color-mix(in srgb,var(--c-accent) 14%,#fff);stroke:#b9a9ae;stroke-width:1}
.is-kids .h-top{fill:#eef7ee;stroke:#7fb487}
.h-obstacle{fill:#f1eeef;stroke:#c9bcc1;stroke-width:1}
.h-buffet{fill:color-mix(in srgb,var(--c-gold) 12%,#fff)}
.h-obstacle-label{font-size:10px;fill:var(--c-soft);font-family:var(--f-label),"DejaVu Sans",sans-serif}
.h-label{fill:var(--c-soft);font-family:var(--f-label),"DejaVu Sans",sans-serif}
.legend{display:flex;gap:8mm;margin-top:3mm;font-size:9pt}
.legend i{display:inline-block;width:3mm;height:3mm;border-radius:50%;margin-inline-end:1.5mm;vertical-align:middle;border:1px solid #c9bcc1}
.legend i.on{background:var(--c-accent);border-color:var(--c-accent)}
.grid{columns:4;column-gap:6mm}
.tbl{break-inside:avoid;margin-bottom:4mm;border:0.8pt solid #e6dde0;border-radius:2mm;padding:2.5mm 3mm}
.tbl h3{display:flex;justify-content:space-between;align-items:baseline;font-size:11.5pt;color:var(--c-accent);border-bottom:0.6pt solid #eee4e6;padding-bottom:1mm;margin-bottom:1mm}
.tbl h3 small{font-size:8.5pt;color:var(--c-soft);font-weight:400}
.tbl ul{list-style:none;font-size:9.5pt}
.tbl li{display:flex;justify-content:space-between;gap:2mm}
.tbl li b{font-weight:400}
.tbl li span{color:var(--c-soft);font-family:"DejaVu Sans",sans-serif;font-size:8.5pt}
.tbl .empty{font-size:9pt;color:#b3a7ab}
.tag{font-size:8pt;color:var(--c-soft);font-weight:400}
@endsection

@section('body')
    @php
        $digits = fn ($n) => \App\Services\Digits::localize($n, 'ar');
        $sections = ['men' => 'رجال · Men', 'women' => 'نساء · Women', 'vip' => 'كبار الضيوف · VIP'];
        $seatedPeople = collect($tables)->sum('people');
        $chairs = collect($tables)->sum('seats');
    @endphp
    <header class="head">
        <div>
            <h1>{{ $event->title }}</h1>
            <p class="sub muted">{{ $event->venue_name }}@if ($date) · {{ $date }}@endif</p>
        </div>
        <div style="text-align:left">
            <p><b>مخطط الجلوس</b> · <span class="latin">Seating chart</span></p>
            <p class="sub muted">{{ $digits($seatedPeople) }} / {{ $digits($chairs) }} مقعد · <span class="latin">{{ $seatedPeople }} / {{ $chairs }} seats</span></p>
        </div>
    </header>

    @include('seating.hall-svg', ['layout' => $layout, 'seated' => $seated])
    <p class="legend muted">
        <span><i class="on"></i>مقعد محجوز · <span class="latin">Seated</span></span>
        <span><i></i>مقعد فارغ · <span class="latin">Free</span></span>
        <span>{{ $layout['name'] ?? '' }}</span>
    </p>

    <section class="page-break">
        <header class="head">
            <h1>من يجلس أين · <span class="latin">Who sits where</span></h1>
            <p class="sub muted">{{ $event->title }}</p>
        </header>
        <div class="grid">
            @foreach ($tables as $t)
                <div class="tbl">
                    <h3>
                        <span>طاولة {{ $digits($t['number']) }} · <span class="latin">Table {{ $t['number'] }}</span>
                            @isset($sections[$t['section']])<span class="tag">({{ $sections[$t['section']] }})</span>@endisset
                            @if (! empty($t['kids']))<span class="tag">(أطفال · Kids)</span>@endif
                        </span>
                        <small class="latin">{{ $t['people'] }}/{{ $t['seats'] }}</small>
                    </h3>
                    @if ($t['families'])
                        <ul>
                            @foreach ($t['families'] as $f)
                                <li><b>{{ $f['name'] }}</b><span>× {{ $f['count'] }}</span></li>
                            @endforeach
                        </ul>
                    @else
                        <p class="empty">فارغة · <span class="latin">Empty</span></p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endsection
