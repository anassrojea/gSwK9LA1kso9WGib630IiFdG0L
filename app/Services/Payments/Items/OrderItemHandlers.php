<?php

namespace App\Services\Payments\Items;

use App\Models\Event;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;

/**
 * The registry of order line types beyond "plan" and "addon" (phase 8D).
 * A feature registers its types from its service provider:
 *
 *     app(OrderItemHandlers::class)->register('photo_book', PhotoBookItem::class);
 *
 * and OrderFulfillment calls fulfill() / refund() for every line of a paid or
 * refunded order. Lines of an unknown type are left alone (they were priced
 * and paid; nothing else happens), so registering is all a new type needs.
 */
class OrderItemHandlers
{
    /** @var array<string, class-string<OrderItemHandler>> */
    private array $handlers = [];

    /** @var array<string, OrderItemHandler> */
    private array $resolved = [];

    public function __construct(private Container $container) {}

    /**
     * @param  class-string<OrderItemHandler>  $handler
     */
    public function register(string $type, string $handler): void
    {
        $this->handlers[$type] = $handler;
        unset($this->resolved[$type]);
    }

    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->handlers);
    }

    public function for(string $type): ?OrderItemHandler
    {
        if (! isset($this->handlers[$type])) {
            return null;
        }

        return $this->resolved[$type] ??= $this->container->make($this->handlers[$type]);
    }

    public function fulfill(Order $order, CarbonImmutable $paidAt): void
    {
        $event = null;
        foreach ($order->items ?? [] as $line) {
            if ($handler = $this->for((string) ($line['type'] ?? ''))) {
                $event ??= $order->event_id ? Event::query()->find($order->event_id) : null;
                $handler->fulfill($order, $line, $event, $paidAt);
            }
        }
    }

    public function refund(Order $order): void
    {
        $event = null;
        foreach ($order->items ?? [] as $line) {
            if ($handler = $this->for((string) ($line['type'] ?? ''))) {
                $event ??= $order->event_id ? Event::query()->find($order->event_id) : null;
                $handler->refund($order, $line, $event);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public function name(array $line, string $locale): ?string
    {
        return $this->for((string) ($line['type'] ?? ''))?->name($line, $locale);
    }

    public function discountable(string $type): bool
    {
        return $this->for($type)?->discountable() ?? false;
    }
}
