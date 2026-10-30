<?php

namespace App\Services\Marketplace;

use App\Models\DiscountCode;
use App\Models\Order;
use App\Services\Marketplace\Items\CalligraphyRequestHandler;
use App\Services\Marketplace\Items\MarketplaceItem;
use App\Services\Marketplace\Items\TemplatePurchaseHandler;
use App\Services\Marketplace\Items\VendorFeatureHandler;
use Carbon\CarbonImmutable;

/**
 * The marketplace's order item types (phase 8C), hooked into the payment core with one
 * line each, like the other phases' item types (8A: OrgSubscriptions::fulfill):
 *
 *   OrderFulfillment::complete()  after grant()           MarketplaceOrders::fulfill($order)
 *   OrderFulfillment::refund()    after the credit note   MarketplaceOrders::refund($order)
 *   OrderPresenter::itemName / TransferGateway::itemsLabel  MarketplaceOrders::name($item, $locale)
 *   Discounts::covers (other types)                          MarketplaceOrders::discountable(...)
 *
 * Orders without these item types are ignored.
 */
class MarketplaceOrders
{
    /** @var array<string, class-string<MarketplaceItem>> */
    public const TYPES = [
        TemplatePurchaseHandler::TYPE => TemplatePurchaseHandler::class,
        VendorFeatureHandler::TYPE => VendorFeatureHandler::class,
        CalligraphyRequestHandler::TYPE => CalligraphyRequestHandler::class,
    ];

    public function for(?string $type): ?MarketplaceItem
    {
        $class = self::TYPES[(string) $type] ?? null;

        return $class ? app($class) : null;
    }

    public function fulfill(Order $order, ?CarbonImmutable $paidAt = null): void
    {
        $paidAt ??= $order->paid_at ?? CarbonImmutable::now();
        foreach (array_values($order->items ?? []) as $index => $line) {
            $this->for($line['type'] ?? null)?->fulfill($order, $line, $index, $paidAt);
        }
    }

    public function refund(Order $order): void
    {
        foreach (array_values($order->items ?? []) as $index => $line) {
            $this->for($line['type'] ?? null)?->refund($order, $line, $index);
        }
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public function name(array $line, string $locale): ?string
    {
        return $this->for($line['type'] ?? null)?->name($line, $locale);
    }

    public function discountable(DiscountCode $code, string $type, string $id): bool
    {
        return $this->for($type)?->discountable($code, $id) ?? false;
    }
}
