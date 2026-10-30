<?php

namespace App\Services\Payments\Items;

use App\Models\Event;
use App\Models\Order;
use App\Services\Journey\CheckoutExtras;
use Carbon\CarbonImmutable;

/**
 * Order lines "loyalty" and "referral_welcome": a negative line taking a
 * percent off the plan (returning customers, or a referred user's first
 * order), applied at checkout instead of a discount code when it takes off
 * more (App\Services\Journey\CheckoutExtras). Nothing to do when paid; the
 * line only needs its name on the invoice.
 */
class PlanReductionItem implements OrderItemHandler
{
    public function fulfill(Order $order, array $line, ?Event $event, CarbonImmutable $paidAt): void {}

    public function refund(Order $order, array $line, ?Event $event): void {}

    public function name(array $line, string $locale): ?string
    {
        return CheckoutExtras::reductionLabel((string) ($line['type'] ?? 'loyalty'), (int) ($line['percent'] ?? 0), $locale);
    }

    public function discountable(): bool
    {
        return false;
    }
}
