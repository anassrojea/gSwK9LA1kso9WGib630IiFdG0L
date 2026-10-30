{{-- Invoice / receipt / credit note, printed to A4 PDF by headless Chromium (App\Services\Invoicing\InvoicePdf). --}}
@php
    $secondaryDir = $secondary === 'ar' ? 'rtl' : 'ltr';
    $taxed = $invoice->isTaxInvoice();
    $both = function (string $key, array $replace = []) use ($label, $secondary, $secondaryDir) {
        [$first, $second] = $label($key, $replace);
        return '<span class="l1">'.e($first).'</span><span class="l2"><bdi lang="'.$secondary.'" dir="'.$secondaryDir.'">'.e($second).'</bdi></span>';
    };
@endphp
<!doctype html>
<html lang="{{ $primary }}" dir="{{ $dir }}">
<head>
<meta charset="utf-8">
<title>{{ $invoice->number }}</title>
<style>
{!! $fontFaces !!}
@page { size: A4; margin: 14mm 14mm 16mm; }
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
    font-family: '{{ \App\Services\Invoicing\InvoicePdf::FONT }}', 'Noto Sans Arabic', 'DejaVu Sans', sans-serif;
    font-size: 10.5pt; line-height: 1.5; color: #1f1f23;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.l1 { display: block; }
.l2 { display: block; font-size: .78em; color: #6b6b76; }
.num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.ltr { direction: ltr; unicode-bidi: isolate; }

header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; padding-bottom: 14px; border-bottom: 2px solid #be123c; }
.brand { font-size: 20pt; font-weight: 700; color: #be123c; line-height: 1.2; }
.brand small { display: block; font-size: 10pt; font-weight: 400; color: #6b6b76; }
.seller { font-size: 9.5pt; color: #3f3f46; margin-top: 6px; }
.seller .kv { display: flex; gap: 8px; align-items: baseline; margin-top: 3px; }
.seller .kv .k { color: #6b6b76; font-size: 8.5pt; }
.seller .kv .k .l1 { display: inline; }
.seller .kv .k .l2 { display: inline; font-size: 1em; margin-inline-start: 4px; }
.doc { text-align: end; }
.doc h1 { margin: 0; font-size: 17pt; line-height: 1.25; }
.doc h1 .l2 { font-size: 10pt; font-weight: 400; }
.qr { display: flex; flex-direction: column; align-items: center; gap: 4px; }
.qr img { width: 33mm; height: 33mm; }
.qr span { font-size: 7.5pt; color: #6b6b76; text-align: center; max-width: 40mm; }

.meta { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 16px 0; }
.box { border: 1px solid #e4e4e7; border-radius: 6px; padding: 8px 10px; }
.box .k { font-size: 8.5pt; color: #6b6b76; }
.box .k .l2 { font-size: .95em; }
.box .v { font-weight: 600; margin-top: 2px; }
.parties { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; }
.parties .v { font-weight: 400; }

.notice { margin: 0 0 12px; padding: 8px 10px; border-radius: 6px; background: #fff1f2; color: #9f1239; font-size: 9.5pt; }

table { width: 100%; border-collapse: collapse; }
thead th { background: #f4f4f5; font-size: 8.5pt; font-weight: 600; color: #3f3f46; padding: 7px 8px; text-align: start; vertical-align: bottom; }
thead th .l2 { font-weight: 400; }
tbody td { padding: 8px; border-bottom: 1px solid #e4e4e7; vertical-align: top; }
td.n, th.n { text-align: end; }
tr.discount td { color: #047857; }

.totals { display: flex; justify-content: flex-end; margin-top: 12px; }
.totals table { width: 62%; }
.totals td { padding: 5px 8px; border-bottom: 1px solid #f4f4f5; }
.totals td:last-child { text-align: end; }
.totals tr.grand td { border-top: 2px solid #1f1f23; border-bottom: 0; font-size: 12.5pt; font-weight: 700; padding-top: 8px; }

.notes { margin-top: 18px; font-size: 9pt; color: #52525b; }
.notes p { margin: 3px 0; }
.notes .l2, footer .l2 { font-size: .9em; }
footer { margin-top: 26px; padding-top: 10px; border-top: 1px solid #e4e4e7; font-size: 9pt; color: #6b6b76; display: flex; justify-content: space-between; gap: 12px; }
</style>
</head>
<body>
<header>
    <div>
        <div class="brand">
            {{ $sellerName[0] ?? $sellerName[1] }}
            @if ($sellerName[1] && $sellerName[1] !== $sellerName[0])<small><bdi lang="{{ $secondary }}" dir="{{ $secondaryDir }}">{{ $sellerName[1] }}</bdi></small>@endif
        </div>
        <div class="seller">
            @if ($sellerAddress)<div>{{ $sellerAddress }}</div>@endif
            @if ($seller['vat_number'] ?? null)<div class="kv"><span class="k">{!! $both('vat_number') !!}</span><span class="ltr num">{{ $seller['vat_number'] }}</span></div>@endif
            @if ($seller['cr_number'] ?? null)<div class="kv"><span class="k">{!! $both('cr_number') !!}</span><span class="ltr num">{{ $seller['cr_number'] }}</span></div>@endif
            @if ($seller['email'] ?? null)<div><span class="ltr">{{ $seller['email'] }}</span></div>@endif
        </div>
    </div>
    <div class="doc">
        <h1>{!! $both('titles.'.$invoice->titleKey()) !!}</h1>
    </div>
    @if ($qr)
        <div class="qr">
            <img src="{{ $qr }}" alt="ZATCA QR">
            <span>{{ $label('qr_caption')[0] }}</span>
        </div>
    @endif
</header>

<section class="meta">
    <div class="box">
        <div class="k">{!! $both('number') !!}</div>
        <div class="v ltr num">{{ $invoice->number }}</div>
    </div>
    <div class="box">
        <div class="k">{!! $both('issued_at') !!}</div>
        <div class="v num">{{-- Arabic: ٣٠/١٠/٢٠٢٦ ٠٢:٥٨ read right to left; English: ISO --}}{{ $primary === 'ar' ? \App\Services\Digits::localize($issued->format('d/m/Y H:i'), 'ar') : $issued->format('Y-m-d H:i') }}</div>
    </div>
    <div class="box">
        @if ($invoice->isCreditNote() && $invoice->original)
            <div class="k">{!! $both('credit_for') !!}</div>
            <div class="v ltr num">{{ $invoice->original->number }}</div>
        @else
            <div class="k">{!! $both('order') !!}</div>
            <div class="v ltr num">{{ $invoice->order?->code }}</div>
        @endif
    </div>
</section>

<section class="parties">
    <div class="box">
        <div class="k">{!! $both('seller') !!}</div>
        <div class="v">{{ $sellerName[0] ?? $sellerName[1] }}@if ($seller['vat_number'] ?? null) · <span class="ltr num">{{ $seller['vat_number'] }}</span>@endif</div>
    </div>
    <div class="box">
        <div class="k">{!! $both('customer') !!}</div>
        <div class="v">
            {{ $invoice->customer['name'] ?? '—' }}
            @if ($invoice->customer['email'] ?? null)<div class="ltr" style="font-size:9pt;color:#52525b">{{ $invoice->customer['email'] }}</div>@endif
        </div>
    </div>
</section>

@if ($invoice->isCreditNote())
    <p class="notice">{!! $both('credit_note_text') !!}</p>
@endif

<table>
    <thead>
        <tr>
            <th>{!! $both('description') !!}</th>
            <th class="n">{!! $both('quantity') !!}</th>
            <th class="n">{!! $both('unit_price') !!}</th>
            @if ($taxed)
                <th class="n">{!! $both('net') !!}</th>
                <th class="n">{!! $both('vat') !!}</th>
            @endif
            <th class="n">{!! $both('amount') !!}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr class="{{ $line['type'] === 'discount' ? 'discount' : '' }}">
                <td>
                    <span class="l1">{{ $line['names'][0] }}</span>
                    @if ($line['names'][1] && $line['names'][1] !== $line['names'][0])<span class="l2"><bdi lang="{{ $secondary }}" dir="{{ $secondaryDir }}">{{ $line['names'][1] }}</bdi></span>@endif
                </td>
                <td class="n num">{{ \App\Services\Digits::localize((string) $line['quantity'], $primary) }}</td>
                <td class="n num">{{ $money((int) $line['unit_amount']) }}</td>
                @if ($taxed)
                    <td class="n num">{{ $money((int) $line['net']) }}</td>
                    <td class="n num">{{ $money((int) $line['vat']) }}</td>
                @endif
                <td class="n num">{{ $money((int) $line['amount']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<section class="totals">
    <table>
        @if ($invoice->discount_cents !== 0)
            <tr><td>{!! $both('gross') !!}</td><td class="num">{{ $money($invoice->gross_cents) }}</td></tr>
        @endif
        @if ($taxed)
            <tr><td>{!! $both('subtotal') !!}</td><td class="num">{{ $money($invoice->subtotal_cents) }}</td></tr>
            <tr><td><span class="l1">{{ $label('vat_rate', ['rate' => $rate[0]])[0] }}</span><span class="l2"><bdi lang="{{ $secondary }}" dir="{{ $secondaryDir }}">{{ $label('vat_rate', ['rate' => $rate[1]])[1] }}</bdi></span></td><td class="num">{{ $money($invoice->vat_cents) }}</td></tr>
            <tr class="grand"><td>{!! $both('total_with_vat') !!}</td><td class="num">{{ $money($invoice->total_cents) }}</td></tr>
        @else
            <tr class="grand"><td>{!! $both('total') !!}</td><td class="num">{{ $money($invoice->total_cents) }}</td></tr>
        @endif
    </table>
</section>

<section class="notes">
    @if ($taxed)
        <p>{!! $both($invoice->prices_include_vat ? 'prices_include_vat' : 'prices_exclude_vat') !!}</p>
    @elseif (! ($seller['vat_number'] ?? null))
        <p>{!! $both('not_registered') !!}</p>
    @else
        <p>{!! $both('no_vat') !!}</p>
    @endif
</section>

<footer>
    <span>{!! $both('thanks') !!}</span>
    <span class="ltr num">{{ $invoice->number }}</span>
</footer>
</body>
</html>
