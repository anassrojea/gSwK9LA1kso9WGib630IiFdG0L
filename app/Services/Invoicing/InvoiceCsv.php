<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The accountant's export (Filament → Invoices → Export CSV): one row per
 * document with the table's filters applied. UTF-8 with a BOM so Excel shows
 * Arabic names; amounts in major units with a dot (credit notes negative),
 * dates in the documents' time zone.
 */
class InvoiceCsv
{
    public const COLUMNS = [
        'number', 'kind', 'type', 'issued_at', 'order', 'credit_for', 'customer_name', 'customer_email', 'customer_country',
        'currency', 'gross', 'discount', 'net', 'vat_rate', 'vat', 'total',
    ];

    /**
     * @param  Builder<Invoice>  $query
     */
    public function download(Builder $query, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            $this->write($out, $query);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  resource  $out
     * @param  Builder<Invoice>  $query
     */
    public function write($out, Builder $query): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::COLUMNS, escape: '');

        $timezone = (string) config('invoicing.timezone', 'Asia/Riyadh');

        $query->clone()->with(['order:id,code', 'original:id,number'])->reorder()->orderBy('issued_at')->orderBy('kind', 'desc')->orderBy('number')
            ->chunk(500, function ($invoices) use ($out, $timezone) {
                foreach ($invoices as $invoice) {
                    fputcsv($out, $this->row($invoice, $timezone), escape: '');
                }
            });
    }

    /**
     * Customer-typed text can't start a spreadsheet formula (CSV injection).
     */
    public static function text(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * @return list<string>
     */
    public function row(Invoice $invoice, string $timezone): array
    {
        $amount = fn (int $minor) => ($minor < 0 ? '-' : '').ZatcaQr::amount($minor, $invoice->currency);

        return [
            $invoice->number,
            $invoice->kind,
            $invoice->type,
            $invoice->issued_at->setTimezone($timezone)->format('Y-m-d H:i:s'),
            (string) $invoice->order?->code,
            (string) $invoice->original?->number,
            static::text($invoice->customer['name'] ?? ''),
            static::text($invoice->customer['email'] ?? ''),
            static::text($invoice->customer['country'] ?? ''),
            $invoice->currency,
            $amount($invoice->gross_cents),
            $amount($invoice->discount_cents),
            $amount($invoice->subtotal_cents),
            Vat::formatRate($invoice->vat_rate_bp, 'en'),
            $amount($invoice->vat_cents),
            $amount($invoice->total_cents),
        ];
    }
}
