{{-- Every family's QR code (plan section 9: printed QR on paper invites), twelve per A4 page. --}}
@extends('pdf.layout', ['page' => 'A4', 'margin' => '10mm'])

@section('style')
.sheet{display:grid;grid-template-columns:repeat(3,1fr);grid-auto-rows:66mm;break-after:page}
.sheet:last-child{break-after:auto}
.qr-card{border:0.6pt dashed #d6cbcf;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1.5mm;padding:3mm;text-align:center;overflow:hidden}
.qr-card svg{width:42mm;height:42mm}
.qr-card b{font-size:10pt;line-height:1.3;max-height:2.6em;overflow:hidden}
.qr-card small{font-size:8pt;color:var(--c-soft)}
@endsection

@section('body')
    @php $digits = fn ($n) => \App\Services\Digits::localize($n, 'ar'); @endphp
    @forelse (array_chunk($guests, 12) as $sheet)
        <div class="sheet">
            @foreach ($sheet as $g)
                <div class="qr-card">
                    {!! $g['svg'] !!}
                    <b>{{ $g['name'] }}</b>
                    <small>{{ $event->title }} · {{ $digits($g['seats']) }} مقاعد · <span class="latin">{{ $g['seats'] }} seats</span></small>
                </div>
            @endforeach
        </div>
    @empty
        <p class="muted">لا يوجد ضيوف بعد · <span class="latin">No guests yet</span></p>
    @endforelse
@endsection
