<?php

namespace App\Services\Marketplace\Items;

use App\Models\DiscountCode;
use App\Models\Order;
use App\Models\VendorFeature;
use App\Models\VendorListing;
use App\Services\Digits;
use App\Services\Marketplace\VendorListings;
use Carbon\CarbonImmutable;

/**
 * Order item vendor_feature: "featured in {city}/{category} for 30 days" (no event).
 *
 *   line     {type: vendor_feature, id: listing id, name, quantity: 1, unit_amount, amount, city, category, days}
 *   paid     a vendor_features row from now (or after a spot still running there) for `days`
 *   refund   the spot ends at once
 */
class VendorFeatureHandler implements MarketplaceItem
{
    public const TYPE = 'vendor_feature';

    public function __construct(private VendorListings $listings) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function fulfill(Order $order, array $line, int $index, CarbonImmutable $paidAt): void
    {
        $listing = VendorListing::query()->find($line['id']);
        if (! $listing || VendorFeature::query()->where('source_order_id', $order->getKey())->exists()) {
            return;
        }

        $this->listings->feature($listing, (string) $line['city'], (int) ($line['days'] ?? config('marketplace.feature.days', 30)), $order);
    }

    public function refund(Order $order, array $line, int $index): void
    {
        $this->listings->endFeaturesOf($order);
    }

    public function name(array $line, string $locale): ?string
    {
        return trans('marketplace.items.vendor_feature', [
            'city' => trans('vendors.cities.'.($line['city'] ?? ''), [], $locale),
            'category' => trans('vendors.categories.'.($line['category'] ?? ''), [], $locale),
            'days' => Digits::localize((int) ($line['days'] ?? 30), $locale),
        ], $locale);
    }

    public function discountable(DiscountCode $code, string $id): bool
    {
        return false;
    }
}
