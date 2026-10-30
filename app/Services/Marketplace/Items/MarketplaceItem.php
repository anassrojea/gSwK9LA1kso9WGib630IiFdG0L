<?php

namespace App\Services\Marketplace\Items;

use App\Models\DiscountCode;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * One marketplace order item type (template_purchase, vendor_feature, calligraphy_request),
 * dispatched by App\Services\Marketplace\MarketplaceOrders. The line itself is the usual
 * snapshot in orders.items: {type, id, name, quantity, unit_amount, amount, …extra keys}.
 *
 * fulfill() runs when the order becomes paid / approved and refund() after a refund, both
 * inside OrderFulfillment's transaction. Both are idempotent.
 */
interface MarketplaceItem
{
    /** The orders.items[].type this class owns. */
    public function type(): string;

    /**
     * @param  array<string, mixed>  $line  the order line
     * @param  int  $index  its position in orders.items (the invoice's lines use the same order)
     */
    public function fulfill(Order $order, array $line, int $index, CarbonImmutable $paidAt): void;

    /**
     * @param  array<string, mixed>  $line
     */
    public function refund(Order $order, array $line, int $index): void;

    /**
     * The line's name on invoices, order pages and the transfer message.
     *
     * @param  array<string, mixed>  $line
     */
    public function name(array $line, string $locale): ?string;

    /** Can a discount code take money off this kind of line (Discounts::covers)? */
    public function discountable(DiscountCode $code, string $id): bool;
}
