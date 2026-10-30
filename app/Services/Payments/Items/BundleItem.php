<?php

namespace App\Services\Payments\Items;

use App\Models\Bundle;
use App\Models\Event;
use App\Models\Order;
use App\Services\Journey\BundleCredits;
use Carbon\CarbonImmutable;

/**
 * Order line "bundle" (plan section 14.2): a family journey bundle, bought
 * without an event. Paying issues its event-plan credits; a refund voids the
 * ones not used yet.
 */
class BundleItem implements OrderItemHandler
{
    public function __construct(private BundleCredits $credits) {}

    public function fulfill(Order $order, array $line, ?Event $event, CarbonImmutable $paidAt): void
    {
        $this->credits->issue($order, $line, $paidAt);
    }

    public function refund(Order $order, array $line, ?Event $event): void
    {
        $this->credits->void($order);
    }

    public function name(array $line, string $locale): ?string
    {
        $bundle = Bundle::query()->find($line['id'] ?? null);

        return $bundle ? trans('journey.bundles.line', ['name' => $bundle->name($locale)], $locale) : null;
    }

    public function discountable(): bool
    {
        return false;
    }
}
