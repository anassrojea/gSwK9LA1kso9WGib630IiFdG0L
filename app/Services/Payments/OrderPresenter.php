<?php

namespace App\Services\Payments;

use App\Models\Invoice;
use App\Models\Order;
use App\Services\Marketplace\MarketplaceOrders;
use App\Services\Org\OrgPlans;
use App\Services\Payments\Gateways\TransferGateway;

/**
 * An order as the customer sees it (Inertia props and GET /orders/{code}/status).
 */
class OrderPresenter
{
    public function __construct(private PaymentGateways $gateways) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Order $order, ?string $locale = null, bool $details = true): array
    {
        $locale ??= app()->getLocale();
        $order->loadMissing(['event:id,title,status', 'invoices']);

        $data = [
            'code' => $order->code,
            'status' => $order->status,
            'method' => $order->method,
            'gateway' => $order->gateway,
            'currency' => $order->currency,
            'requested_currency' => $order->requested_currency,
            'total' => $order->total_cents,
            'total_formatted' => Currencies::format($order->total_cents, $order->currency, $locale),
            'discount' => $order->discount_code ? [
                'code' => $order->discount_code,
                'amount' => (int) $order->discount_cents,
                'amount_formatted' => Currencies::format((int) $order->discount_cents, $order->currency, $locale),
                'subtotal_formatted' => Currencies::format($order->subtotal(), $order->currency, $locale),
            ] : null,
            'summary' => TransferGateway::itemsLabel($order, $locale),
            'created_at' => $order->created_at?->toIso8601String(),
            'expires_at' => $order->expires_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'event' => $order->event ? ['id' => $order->event->id, 'title' => $order->event->title] : null,
            'url' => route('dashboard.orders.show', $order, false),
            // VAT added on top at checkout (VAT-exclusive prices only)
            'vat' => (int) $order->vat_cents > 0 ? [
                'amount' => (int) $order->vat_cents,
                'amount_formatted' => Currencies::format((int) $order->vat_cents, $order->currency, $locale),
            ] : null,
            // the invoice or receipt, and the credit note after a refund (phase 6B)
            'invoices' => $order->invoices->map(fn (Invoice $invoice) => [
                'number' => $invoice->number,
                'kind' => $invoice->kind,
                'title' => trans('invoices.titles.'.$invoice->titleKey(), [], $locale),
                'issued_at' => $invoice->issued_at->toIso8601String(),
                'total_formatted' => Currencies::format($invoice->total_cents, $invoice->currency, $locale),
                'url' => route('invoices.download', $invoice, false),
            ])->values()->all(),
        ];

        if (! $details) {
            return $data;
        }

        $transfer = $this->gateways->find(TransferGateway::ID);

        return $data + [
            'items' => collect($order->items ?? [])->map(fn (array $item) => [
                'type' => $item['type'],
                'id' => $item['id'],
                'name' => $this->itemName($item, $locale),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'amount' => (int) $item['amount'],
                'amount_formatted' => Currencies::format((int) $item['amount'], $order->currency, $locale),
            ])->values()->all(),
            'rejection_reason' => $order->status === 'rejected' ? $order->rejection_reason : null,
            'receipt_sent_at' => $order->latestReceipt?->received_at?->toIso8601String(),
            // bank details stay visible while the order can still be paid by transfer
            'transfer' => $order->isTransfer() && $order->isPending() && $transfer instanceof TransferGateway
                ? $transfer->instructions($order, $locale)
                : null,
            'can_retry' => $order->method === 'card' && $order->status === 'awaiting_payment',
            'urls' => [
                'status' => route('orders.status', $order, false),
                'receipt' => route('orders.receipt', $order, false),
                'retry' => route('orders.retry', $order, false),
                'back' => $order->event_id
                    ? ($order->event?->status === 'draft' || ! $order->isFulfilled()
                        ? route('create.step', [$order->event_id, 'review'], false)
                        : route('dashboard.events.show', $order->event_id, false))
                    // an organization's subscription order (phase 8A) goes back to its billing page
                    : ($order->org_id ? route('org.billing', $order->org_id, false) : route('dashboard', absolute: false)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function itemName(array $item, string $locale): string
    {
        if ($item['type'] === 'subscription') {
            return OrgPlans::lineName((string) $item['id'], $locale);
        }

        if (($handled = app(MarketplaceOrders::class)->name($item, $locale)) !== null) {
            return $handled;
        }
        $key = $item['type'] === 'plan' ? "app.wizard.tiers.{$item['id']}" : "app.checkout.addons.{$item['id']}.name";
        $name = trans($key, [], $locale);

        return $name === $key ? (string) ($item['name'] ?? $item['id']) : $name;
    }
}
