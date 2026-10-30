<?php

namespace App\Services\Invoicing;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Payments\OrderPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Issues the document for a paid or approved order, and the credit note for a
 * refund (called by OrderFulfillment inside its transaction, so the number,
 * the invoice and the order's new status commit or roll back together).
 *
 *   type    simplified_tax_invoice when Vat::applies(order currency, customer country), else receipt
 *   lines   the order's priced lines (the checkout snapshot), plus a negative discount line
 *   VAT     inclusive prices: extracted from the order total; exclusive: orders.vat_cents (added at checkout)
 *   QR      ZATCA phase 1, simplified tax invoices only
 *
 * Both are idempotent: one invoice and one credit note per order at most.
 */
class Invoicer
{
    public function __construct(
        private InvoiceNumbers $numbers,
        private OrderPresenter $presenter,
    ) {}

    public function issue(Order $order): Invoice
    {
        return DB::transaction(function () use ($order) {
            $existing = Invoice::query()->where('order_id', $order->getKey())->where('kind', Invoice::KIND_INVOICE)->first();
            if ($existing) {
                return $existing;
            }

            $order->loadMissing('user');
            $issuedAt = CarbonImmutable::now();
            $customer = [
                'name' => $order->user?->name,
                'email' => $order->user?->email,
                'country' => $order->user?->country,
            ];

            $exclusive = (int) $order->vat_cents > 0;
            $taxed = $exclusive || Vat::applies($order->currency, $customer['country']);
            $rate = $taxed ? Vat::rateBp() : 0;

            $total = (int) $order->total_cents;
            $vat = match (true) {
                ! $taxed => 0,
                $exclusive => (int) $order->vat_cents,
                default => Vat::inGross($total, $rate),
            };

            $lines = $this->lines($order);
            $amounts = array_column($lines, 'amount');
            $lineVat = $taxed ? Vat::allocate($amounts, $vat, $rate, ! $exclusive) : array_fill(0, count($lines), 0);
            foreach ($lines as $i => $line) {
                $lines[$i]['vat'] = $lineVat[$i];
                // inclusive: the amount holds the VAT; exclusive: the amount is already net
                $lines[$i]['net'] = $exclusive ? $line['amount'] : $line['amount'] - $lineVat[$i];
            }

            $number = $this->numbers->next((string) config('invoicing.prefix', 'INV'), $this->year($issuedAt));
            $seller = $this->seller();
            $type = $taxed ? Invoice::TYPE_TAX : Invoice::TYPE_RECEIPT;

            $invoice = Invoice::query()->create([
                ...$number,
                'kind' => Invoice::KIND_INVOICE,
                'type' => $type,
                'order_id' => $order->getKey(),
                'user_id' => $order->user_id,
                'customer' => $customer,
                'seller' => $seller,
                'lines' => $lines,
                'currency' => $order->currency,
                'gross_cents' => $order->subtotal(),
                'discount_cents' => (int) $order->discount_cents,
                'subtotal_cents' => $total - $vat,
                'vat_cents' => $vat,
                'total_cents' => $total,
                'vat_rate_bp' => $rate,
                'prices_include_vat' => ! $exclusive,
                'locale' => $order->user?->preferredLocale() ?? config('app.locale'),
                'issued_at' => $issuedAt,
                'qr' => $type === Invoice::TYPE_TAX
                    ? ZatcaQr::payload($seller['name_ar'] ?: $seller['name_en'], (string) $seller['vat_number'], $issuedAt, ZatcaQr::amount($total, $order->currency), ZatcaQr::amount($vat, $order->currency))
                    : null,
            ]);

            AuditLog::record('invoice.issued', $invoice, [
                'number' => $invoice->number,
                'order' => $order->code,
                'type' => $type,
                'total' => $total,
                'vat' => $vat,
                'currency' => $order->currency,
            ]);

            return $invoice;
        });
    }

    /**
     * The refund's credit note: the invoice's lines and totals negated, a number from
     * the credit-note series, pointing at the invoice. Orders paid before invoicing
     * existed have no invoice to credit; that is logged and nothing is issued.
     */
    public function creditNote(Order $order, ?string $reason = null): ?Invoice
    {
        return DB::transaction(function () use ($order, $reason) {
            $invoice = Invoice::query()->where('order_id', $order->getKey())->where('kind', Invoice::KIND_INVOICE)->lockForUpdate()->first();
            if (! $invoice) {
                AuditLog::record('invoice.credit_note_skipped', $order, ['reason' => 'no invoice for this order']);

                return null;
            }

            $existing = Invoice::query()->where('order_id', $order->getKey())->where('kind', Invoice::KIND_CREDIT_NOTE)->first();
            if ($existing) {
                return $existing;
            }

            $issuedAt = CarbonImmutable::now();
            $negate = fn (array $line) => [
                ...$line,
                'amount' => -$line['amount'],
                'unit_amount' => -($line['unit_amount'] ?? 0),
                'net' => -$line['net'],
                'vat' => -$line['vat'],
            ];

            $note = Invoice::query()->create([
                ...$this->numbers->next((string) config('invoicing.credit_note_prefix', 'CN'), $this->year($issuedAt)),
                'kind' => Invoice::KIND_CREDIT_NOTE,
                'type' => $invoice->type,
                'credit_for_id' => $invoice->getKey(),
                'order_id' => $order->getKey(),
                'user_id' => $invoice->user_id,
                'customer' => $invoice->customer,
                'seller' => $invoice->seller,
                'lines' => array_map($negate, $invoice->lines),
                'currency' => $invoice->currency,
                'gross_cents' => -$invoice->gross_cents,
                'discount_cents' => -$invoice->discount_cents,
                'subtotal_cents' => -$invoice->subtotal_cents,
                'vat_cents' => -$invoice->vat_cents,
                'total_cents' => -$invoice->total_cents,
                'vat_rate_bp' => $invoice->vat_rate_bp,
                'prices_include_vat' => $invoice->prices_include_vat,
                'locale' => $invoice->locale,
                'issued_at' => $issuedAt,
                'qr' => $invoice->isTaxInvoice()
                    ? ZatcaQr::payload(
                        $invoice->seller['name_ar'] ?: $invoice->seller['name_en'],
                        (string) $invoice->seller['vat_number'],
                        $issuedAt,
                        ZatcaQr::amount($invoice->total_cents, $invoice->currency),
                        ZatcaQr::amount($invoice->vat_cents, $invoice->currency),
                    )
                    : null,
            ]);

            AuditLog::record('invoice.credit_note_issued', $note, array_filter([
                'number' => $note->number,
                'invoice' => $invoice->number,
                'order' => $order->code,
                'total' => $note->total_cents,
                'reason' => $reason,
            ], fn ($v) => $v !== null));

            return $note;
        });
    }

    /** The series year, in the documents' own time zone (INVOICE_TIMEZONE). */
    private function year(CarbonImmutable $issuedAt): int
    {
        return (int) $issuedAt->setTimezone((string) config('invoicing.timezone', 'Asia/Riyadh'))->year;
    }

    /**
     * The seller as configured now (snapshotted on each document).
     *
     * @return array<string, ?string>
     */
    public function seller(): array
    {
        return collect(config('invoicing.seller'))
            ->map(fn ($value) => filled($value) ? (string) $value : null)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(Order $order): array
    {
        $lines = collect($order->items ?? [])->map(fn (array $item) => [
            'type' => $item['type'],
            'id' => $item['id'],
            'name_ar' => $this->presenter->itemName($item, 'ar'),
            'name_en' => $this->presenter->itemName($item, 'en'),
            'quantity' => (int) ($item['quantity'] ?? 1),
            'unit_amount' => (int) ($item['unit_amount'] ?? $item['amount']),
            'amount' => (int) $item['amount'],
        ])->values()->all();

        if ((int) $order->discount_cents > 0) {
            $lines[] = [
                'type' => 'discount',
                'id' => (string) $order->discount_code,
                'name_ar' => trans('invoices.discount_line', ['code' => $order->discount_code], 'ar'),
                'name_en' => trans('invoices.discount_line', ['code' => $order->discount_code], 'en'),
                'quantity' => 1,
                'unit_amount' => -(int) $order->discount_cents,
                'amount' => -(int) $order->discount_cents,
            ];
        }

        return $lines;
    }
}
