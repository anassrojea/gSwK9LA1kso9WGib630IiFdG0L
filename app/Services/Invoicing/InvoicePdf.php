<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Services\OgImage\Chromium;
use App\Services\OgImage\FontEmbedder;
use App\Services\Payments\Currencies;
use App\Services\Payments\OrderPresenter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The A4 PDF of an invoice, receipt or credit note: resources/views/invoices/pdf.blade.php
 * printed by headless Chromium (App\Services\OgImage\Chromium::pdf), so Arabic is shaped
 * by a real browser. Bilingual: the customer's language first (direction and order),
 * the other one under each label. The ZATCA QR is an inline SVG (chillerlan/php-qrcode).
 *
 * Rendered on first download and cached on the media disk at
 * invoices/{invoice_id}/{random}.pdf (private visibility, unguessable name);
 * invoices.pdf_path remembers it. Documents never change, so neither does the file.
 */
class InvoicePdf
{
    /** IBM Plex Sans Arabic covers Arabic and Latin; inlined by FontEmbedder (no network at render time). */
    public const FONT = 'IBM Plex Sans Arabic';

    public function __construct(
        private Chromium $chromium,
        private FontEmbedder $fonts,
    ) {}

    public function available(): bool
    {
        return $this->chromium->available();
    }

    public function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.media_disk', 'public'));
    }

    /**
     * The PDF's path on the media disk, rendered first when missing.
     */
    public function ensure(Invoice $invoice): string
    {
        $disk = $this->disk();
        if ($invoice->pdf_path && $disk->exists($invoice->pdf_path)) {
            return $invoice->pdf_path;
        }

        $timeout = (int) config('invoicing.pdf_timeout', 30);

        // one render per document even when it is clicked twice
        return Cache::lock('invoice-pdf:'.$invoice->getKey(), $timeout + 15)->block($timeout, function () use ($invoice, $disk, $timeout) {
            $invoice->refresh();
            if ($invoice->pdf_path && $disk->exists($invoice->pdf_path)) {
                return $invoice->pdf_path;
            }

            $pdf = $this->chromium->pdf($this->html($invoice), $timeout);
            $path = trim((string) config('invoicing.pdf_directory', 'invoices'), '/').'/'.$invoice->getKey().'/'.Str::random(40).'.pdf';
            $disk->put($path, $pdf, ['visibility' => 'private']);

            // the only column that changes after issue
            $invoice->forceFill(['pdf_path' => $path])->saveQuietly();

            return $path;
        });
    }

    public function filename(Invoice $invoice): string
    {
        return $invoice->number.'.pdf';
    }

    /**
     * Money with every decimal of the currency (109.00, not 109), as accountants expect.
     */
    public static function money(int $minor, string $currency, string $locale): string
    {
        $exponent = Currencies::exponent($currency);
        $formatter = new \NumberFormatter($locale === 'ar' ? 'ar@numbers=arab' : 'en', \NumberFormatter::CURRENCY);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $exponent);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $exponent);

        return (string) $formatter->formatCurrency(Currencies::toMajor($minor, $currency), $currency);
    }

    /**
     * The document as self-contained HTML (fonts and QR inlined).
     */
    public function html(Invoice $invoice): string
    {
        $primary = in_array($invoice->locale, ['ar', 'en'], true) ? $invoice->locale : 'ar';
        $secondary = $primary === 'ar' ? 'en' : 'ar';
        $timezone = (string) config('invoicing.timezone', 'Asia/Riyadh');
        $money = fn (int $minor) => static::money($minor, $invoice->currency, $primary);
        $label = fn (string $key, array $replace = []) => [
            trans("invoices.{$key}", $replace, $primary),
            trans("invoices.{$key}", $replace, $secondary),
        ];

        $invoice->loadMissing(['order', 'original']);

        return view('invoices.pdf', [
            'invoice' => $invoice,
            'primary' => $primary,
            'secondary' => $secondary,
            'dir' => $primary === 'ar' ? 'rtl' : 'ltr',
            'label' => $label,
            'money' => $money,
            'title' => $label('titles.'.$invoice->titleKey()),
            'issued' => $invoice->issued_at->setTimezone($timezone),
            'rate' => [Vat::formatRate($invoice->vat_rate_bp, $primary), Vat::formatRate($invoice->vat_rate_bp, $secondary)],
            'seller' => $invoice->seller,
            'sellerName' => [$invoice->seller["name_{$primary}"] ?? null, $invoice->seller["name_{$secondary}"] ?? null],
            'sellerAddress' => $invoice->seller["address_{$primary}"] ?? $invoice->seller["address_{$secondary}"] ?? null,
            'lines' => collect($invoice->lines)->map(fn (array $line) => [
                ...$line,
                'names' => [$line["name_{$primary}"] ?? $line['id'], $line["name_{$secondary}"] ?? null],
            ])->all(),
            'qr' => $invoice->qr ? ZatcaQr::svgDataUri($invoice->qr) : null,
            'fontFaces' => $this->fonts->css(['fonts' => ['body' => self::FONT]]),
            'presenter' => app(OrderPresenter::class),
        ])->render();
    }
}
