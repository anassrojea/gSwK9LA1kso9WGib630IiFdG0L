<?php

namespace App\Services\Payments\Items;

use App\Models\Event;
use App\Models\Order;
use Carbon\CarbonImmutable;

/**
 * What one order line type does when its order is paid or refunded (phase 8D).
 * "plan" and "addon" lines stay in OrderFulfillment itself; every other type
 * (save_the_date, thank_you, highlight_video, photo_book, bundle, bundle_credit,
 * referral_credit, …) is a small class registered with OrderItemHandlers, so
 * new products never restructure the payment core.
 *
 * Both methods run inside OrderFulfillment's transaction, after the plan and
 * add-ons were granted and before the invoice is issued.
 */
interface OrderItemHandler
{
    /**
     * The order was paid or approved. $event is null for orders without one (a bundle).
     *
     * @param  array<string, mixed>  $line  the priced line snapshotted at checkout
     */
    public function fulfill(Order $order, array $line, ?Event $event, CarbonImmutable $paidAt): void;

    /**
     * The order was refunded. Entitlements carrying the order as source_order_id
     * are already ended by OrderFulfillment::refund.
     *
     * @param  array<string, mixed>  $line
     */
    public function refund(Order $order, array $line, ?Event $event): void;

    /**
     * The line's name on the order page, the invoice and the transfer note; null: the snapshotted name.
     *
     * @param  array<string, mixed>  $line
     */
    public function name(array $line, string $locale): ?string;

    /**
     * Discount codes that apply to add-ons also apply to this line (App\Services\Payments\Discounts::covers).
     */
    public function discountable(): bool;
}
