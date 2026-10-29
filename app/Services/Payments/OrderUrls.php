<?php

namespace App\Services\Payments;

use App\Models\Order;

/**
 * Where a customer comes back to after a card checkout: the wizard's review
 * step or the order page in the dashboard, with ?checkout=success|cancelled.
 * The page only shows the status; the webhook alone marks the order paid.
 */
class OrderUrls
{
    public static function back(Order $order, string $returnTo, string $outcome): string
    {
        if ($returnTo === 'wizard' && $order->event_id) {
            return route('create.step', [$order->event_id, 'review', 'order' => $order->code, 'checkout' => $outcome]);
        }

        return route('dashboard.orders.show', [$order, 'checkout' => $outcome]);
    }

    public static function success(Order $order, string $returnTo): string
    {
        return static::back($order, $returnTo, 'success');
    }

    public static function cancel(Order $order, string $returnTo): string
    {
        return static::back($order, $returnTo, 'cancelled');
    }
}
