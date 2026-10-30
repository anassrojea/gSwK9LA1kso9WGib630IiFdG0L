<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\Analytics\Analytics;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * "order_paid" for the draft → paid KPI (plan section 22), sent from the
 * server when money is confirmed: a card webhook (paid), an admin approving
 * a transfer (approved) or a 100% discount code (paid at checkout).
 * Registered in AppServiceProvider.
 */
class OrderAnalytics implements ShouldHandleEventsAfterCommit
{
    public function __construct(private Analytics $analytics) {}

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status') || ! in_array($order->status, Order::FULFILLED, true)) {
            return;
        }

        $items = collect($order->items ?? []);

        $this->analytics->capture('order_paid', (string) ($order->user_id ?? 'anonymous'), [
            'order' => $order->code,
            'status' => $order->status,
            'method' => $order->method,
            'gateway' => $order->gateway,
            'currency' => $order->currency,
            'total' => $order->total_cents,
            'discounted' => (int) $order->discount_cents > 0,
            'plan' => $items->firstWhere('type', 'plan')['id'] ?? null,
            'addons' => $items->where('type', 'addon')->pluck('id')->values()->all(),
            'event_id' => $order->event_id,
        ]);
    }
}
