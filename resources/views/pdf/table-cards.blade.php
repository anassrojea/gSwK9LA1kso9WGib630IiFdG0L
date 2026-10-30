{{-- Per-table name cards (plan section 7.3): six per A4 page, cut along the dashed lines and fold in half. --}}
@extends('pdf.layout', ['page' => 'A4', 'margin' => '8mm'])

@section('style')
.sheet{display:grid;grid-template-columns:1fr 1fr;grid-auto-rows:92mm;gap:0;break-after:page}
.sheet:last-child{break-after:auto}
.card{border:0.6pt dashed #c9bcc1;padding:7mm 8mm;display:flex;flex-direction:column;align-items:center;text-align:center;gap:1.5mm;overflow:hidden;position:relative}
.card::before{content:"";position:absolute;inset:3.5mm;border:1pt solid var(--c-gold);border-radius:2.5mm;pointer-events:none}
.label{font-family:var(--f-label),"DejaVu Sans",sans-serif;color:var(--c-gold);font-size:10pt;letter-spacing:.02em}
.num{font-family:var(--f-display),serif;color:var(--c-accent);font-size:40pt;line-height:1.1;display:flex;align-items:baseline;gap:4mm}
.num .latin{font-size:22pt;color:var(--c-soft)}
.names{list-style:none;font-size:10.5pt;line-height:1.45;margin-top:1mm;max-height:40mm;overflow:hidden}
.names span{color:var(--c-soft);font-size:8.5pt;font-family:"DejaVu Sans",sans-serif}
.foot{margin-top:auto;font-size:8pt;color:var(--c-soft)}
@endsection

@section('body')
    @php $digits = fn ($n) => \App\Services\Digits::localize($n, 'ar'); @endphp
    @forelse (array_chunk($tables, 6) as $sheet)
        <div class="sheet">
            @foreach ($sheet as $t)
                <div class="card">
                    <span class="label">طاولة · <span class="latin">Table</span></span>
                    <div class="num"><span>{{ $digits($t['number']) }}</span><span class="latin">{{ $t['number'] }}</span></div>
                    @if (! empty($t['label']) || ! empty($t['kids']))
                        <span class="label">{{ $t['label'] ?? '' }}@if (! empty($t['kids'])) {{ ! empty($t['label']) ? '·' : '' }} أطفال · <span class="latin">Kids</span>@endif</span>
                    @endif
                    <ul class="names">
                        @foreach (array_slice($t['families'], 0, 10) as $f)
                            <li>{{ $f['name'] }} @if ($f['count'] > 1)<span>× {{ $f['count'] }}</span>@endif</li>
                        @endforeach
                        @if (count($t['families']) > 10)
                            <li><span>+{{ count($t['families']) - 10 }}</span></li>
                        @endif
                    </ul>
                    <p class="foot">{{ $event->title }}</p>
                </div>
            @endforeach
        </div>
    @empty
        <p class="muted">لا يوجد ضيوف على الطاولات بعد · <span class="latin">Nobody is seated yet</span></p>
    @endforelse
@endsection
