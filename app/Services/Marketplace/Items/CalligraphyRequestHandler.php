<?php

namespace App\Services\Marketplace\Items;

use App\Models\AuditLog;
use App\Models\CalligraphyRequest;
use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\VendorListing;
use Carbon\CarbonImmutable;

/**
 * Order item calligraphy_request: a custom name logo from a calligrapher (plan section 14.6).
 *
 *   line     {type: calligraphy_request, id: listing id, name, quantity: 1, unit_amount, amount, request_id}
 *   paid     the request goes from awaiting_payment to new: the calligrapher sees it in their portal
 *   refund   the request is cancelled
 *
 * Kept light on purpose: the calligrapher is paid outside Daawa for now (no ledger).
 */
class CalligraphyRequestHandler implements MarketplaceItem
{
    public const TYPE = 'calligraphy_request';

    public function type(): string
    {
        return self::TYPE;
    }

    public function fulfill(Order $order, array $line, int $index, CarbonImmutable $paidAt): void
    {
        $request = CalligraphyRequest::query()->whereKey($line['request_id'] ?? null)->where('status', 'awaiting_payment')->first();
        if ($request) {
            $request->update(['status' => 'new', 'order_id' => $order->getKey()]);
            AuditLog::record('calligraphy.paid', $request, ['order' => $order->code]);
        }
    }

    public function refund(Order $order, array $line, int $index): void
    {
        CalligraphyRequest::query()->whereKey($line['request_id'] ?? null)->where('status', '!=', 'cancelled')->update(['status' => 'cancelled']);
    }

    public function name(array $line, string $locale): ?string
    {
        $listing = VendorListing::query()->find($line['id']);

        return trans('marketplace.items.calligraphy_request', ['vendor' => $listing?->name($locale) ?? ($line['name'] ?? '')], $locale);
    }

    public function discountable(DiscountCode $code, string $id): bool
    {
        return false;
    }
}
