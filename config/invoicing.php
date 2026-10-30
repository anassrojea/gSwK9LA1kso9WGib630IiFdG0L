<?php

$list = fn (?string $value) => array_values(array_filter(array_map(
    fn (string $item) => strtoupper(trim($item)),
    explode(',', (string) $value),
)));

/*
| Invoices, receipts and credit notes (phase 6B, docs/INVOICES.md). One document is issued per
| paid or approved order (App\Services\Invoicing\Invoicer, called by OrderFulfillment) and a
| credit note per refund. Until INVOICE_VAT_NUMBER is set the owner is treated as not
| VAT-registered: every document is a plain receipt with no VAT. Once it is set, orders in a
| VAT currency (and country, if listed) get a ZATCA phase-1 simplified tax invoice with its QR.
| ZATCA phase 2 (e-invoicing integration) is not implemented.
*/

return [

    // The seller as printed on every document (snapshotted on the invoice row when issued)
    'seller' => [
        'name_ar' => env('INVOICE_SELLER_NAME_AR', 'دعوة'),
        'name_en' => env('INVOICE_SELLER_NAME_EN', 'Daawa'),
        // 15 digits, starting and ending with 3. Empty: not VAT-registered, receipts only.
        'vat_number' => env('INVOICE_VAT_NUMBER'),
        // Commercial Registration number (optional, printed when set)
        'cr_number' => env('INVOICE_CR_NUMBER'),
        'address_ar' => env('INVOICE_SELLER_ADDRESS_AR'),
        'address_en' => env('INVOICE_SELLER_ADDRESS_EN'),
        'email' => env('INVOICE_SELLER_EMAIL', env('MAIL_FROM_ADDRESS')),
    ],

    'vat' => [
        // percent, e.g. 15 (Saudi Arabia). Stored on each invoice in basis points (1500).
        'rate' => (float) env('INVOICE_VAT_RATE', 15),
        // true: the price list already includes VAT, so VAT is extracted from the total.
        // false: prices are net and checkout adds VAT on top (orders.vat_cents).
        'prices_include_vat' => (bool) env('INVOICE_PRICES_INCLUDE_VAT', true),
        // order currencies VAT applies to (comma separated). Default: SAR orders only.
        'currencies' => $list(env('INVOICE_VAT_CURRENCIES', 'SAR')),
        // customer countries VAT applies to (ISO alpha-2, comma separated). Empty: any country.
        'countries' => $list(env('INVOICE_VAT_COUNTRIES', '')),
    ],

    // dates printed on documents (the QR's timestamp is UTC)
    'timezone' => env('INVOICE_TIMEZONE', 'Asia/Riyadh'),

    // INV-2026-000123 and CN-2026-000007: one gap-free series per prefix and year
    'prefix' => env('INVOICE_PREFIX', 'INV'),
    'credit_note_prefix' => env('INVOICE_CREDIT_NOTE_PREFIX', 'CN'),
    'digits' => 6,

    // The PDF is rendered on first download (headless Chromium, App\Services\OgImage\Chromium)
    // and cached on the media disk under this directory (private visibility).
    'pdf_directory' => 'invoices',
    'pdf_timeout' => (int) env('INVOICE_PDF_TIMEOUT', 30),

];
