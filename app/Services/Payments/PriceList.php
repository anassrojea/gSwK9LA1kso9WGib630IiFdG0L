<?php

namespace App\Services\Payments;

use App\Models\Addon;
use App\Models\Plan;
use App\Models\Price;

/**
 * Prices per currency (table prices, edited in Filament). A plan or add-on
 * without a USD row falls back to its price_cents column, which has always
 * been the USD price. Other currencies have no fallback: an item with no
 * price in a currency can't be bought in it.
 */
class PriceList
{
    public function amount(Plan|Addon $item, string $currency): ?int
    {
        $row = $item->relationLoaded('prices')
            ? $item->prices->firstWhere('currency', $currency)
            : $item->prices()->where('currency', $currency)->first();

        if ($row instanceof Price) {
            return $row->amount;
        }

        if ($currency === Currencies::fallback() && strtoupper((string) $item->currency) === $currency) {
            return (int) $item->price_cents;
        }

        return null;
    }

    /**
     * Every currency's price of an item (null where it has none).
     *
     * @return array<string, ?int>
     */
    public function all(Plan|Addon $item): array
    {
        $item->loadMissing('prices');

        return collect(Currencies::all())->mapWithKeys(fn (string $currency) => [$currency => $this->amount($item, $currency)])->all();
    }

    /**
     * Set (or replace) an item's price in a currency.
     */
    public function set(Plan|Addon $item, string $currency, int $amount, bool $placeholder = false): Price
    {
        $price = Price::query()->updateOrCreate(
            ['priceable_type' => $item->getMorphClass(), 'priceable_id' => $item->getKey(), 'currency' => $currency],
            ['amount' => $amount, 'placeholder' => $placeholder],
        );
        $item->unsetRelation('prices');

        return $price;
    }
}
