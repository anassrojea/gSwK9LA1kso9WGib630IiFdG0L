<?php

namespace App\Services\Payments\Items;

use App\Models\Entitlement;
use App\Models\Event;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * A product sold like an add-on (config payments.addons.{id} with an "item"
 * type): paying grants its feature to the event, like OrderFulfillment does
 * for add-ons, with the order as the entitlement's source so a refund ends it.
 * Save the date, the thank-you card and the highlight video are just this.
 */
class FeatureItem implements OrderItemHandler
{
    public function fulfill(Order $order, array $line, ?Event $event, CarbonImmutable $paidAt): void
    {
        $definition = config('payments.addons.'.($line['id'] ?? ''));
        if (! $event || ! $definition) {
            return;
        }

        $until = isset($definition['days']) ? $paidAt->addDays((int) $definition['days']) : null;

        Entitlement::query()->create([
            'event_id' => $event->getKey(),
            'feature' => $definition['feature'],
            'until' => $until,
            'source_order_id' => $order->getKey(),
        ]);
    }

    public function refund(Order $order, array $line, ?Event $event): void
    {
        // the entitlement carries the order as its source: OrderFulfillment::refund already ended it
    }

    public function name(array $line, string $locale): ?string
    {
        $key = 'app.checkout.addons.'.($line['id'] ?? '').'.name';
        $name = trans($key, [], $locale);

        return $name === $key ? null : $name;
    }

    public function discountable(): bool
    {
        return true;
    }
}
