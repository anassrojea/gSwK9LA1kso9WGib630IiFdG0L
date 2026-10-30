<?php

namespace App\Services\Invoicing;

use App\Services\Digits;

/**
 * VAT rules and exact integer math (config/invoicing.php, docs/INVOICES.md).
 *
 * Amounts are integers in minor units, the rate in basis points (1500 = 15 %).
 * Rounding: half away from zero to the currency's minor unit, done once on the
 * document total (the authoritative figure); per-line VAT uses the same rule
 * and any difference is put on the largest line, so lines always add up.
 *
 *   inclusive (prices include VAT):  vat = round(total × rate / (10000 + rate)),  net = total − vat
 *   exclusive (prices are net):      vat = round(net × rate / 10000),             total = net + vat
 *
 * e.g. 109.00 SAR inclusive at 15 %: 10900 × 1500 / 11500 = 1421.739… → 1422, net 9478.
 */
class Vat
{
    /** VAT-registered: a VAT number is configured. Otherwise every document is a receipt. */
    public static function registered(): bool
    {
        return filled(config('invoicing.seller.vat_number'));
    }

    public static function rateBp(): int
    {
        return (int) round(((float) config('invoicing.vat.rate', 15)) * 100);
    }

    public static function pricesIncludeVat(): bool
    {
        return (bool) config('invoicing.vat.prices_include_vat', true);
    }

    /**
     * Does VAT apply to an order in $currency for a customer in $country?
     * Only when registered, the currency is listed and (if countries are listed) the country is too.
     */
    public static function applies(string $currency, ?string $country = null): bool
    {
        if (! static::registered() || static::rateBp() <= 0) {
            return false;
        }

        if (! in_array(strtoupper($currency), (array) config('invoicing.vat.currencies', []), true)) {
            return false;
        }

        $countries = (array) config('invoicing.vat.countries', []);

        return $countries === [] || in_array(strtoupper((string) $country), $countries, true);
    }

    /**
     * VAT to add on top of net prices at checkout (0 when prices include VAT or VAT does not apply).
     */
    public static function checkoutSurcharge(int $net, string $currency, ?string $country): int
    {
        return ! static::pricesIncludeVat() && static::applies($currency, $country)
            ? static::onNet($net, static::rateBp())
            : 0;
    }

    /** The VAT inside a VAT-inclusive amount. */
    public static function inGross(int $gross, int $rateBp): int
    {
        return static::divideRounded($gross * $rateBp, 10000 + $rateBp);
    }

    /** The VAT on a net amount. */
    public static function onNet(int $net, int $rateBp): int
    {
        return static::divideRounded($net * $rateBp, 10000);
    }

    /**
     * Per-line VAT that adds up to $total exactly: each line by the same formula, the
     * rounding difference put on the line with the largest absolute amount.
     *
     * @param  list<int>  $amounts
     * @return list<int>
     */
    public static function allocate(array $amounts, int $total, int $rateBp, bool $inclusive): array
    {
        if ($amounts === []) {
            return [];
        }

        $vat = array_map(fn (int $a) => $inclusive ? static::inGross($a, $rateBp) : static::onNet($a, $rateBp), $amounts);
        $diff = $total - array_sum($vat);

        if ($diff !== 0) {
            $sizes = array_map('abs', $amounts);
            $vat[array_search(max($sizes), $sizes, true)] += $diff;
        }

        return $vat;
    }

    /**
     * "15" or "١٥" (or "12.5"): the rate in percent for labels.
     */
    public static function formatRate(int $rateBp, string $locale): string
    {
        $value = rtrim(rtrim(number_format($rateBp / 100, 2, '.', ''), '0'), '.');

        return Digits::localize($value, $locale);
    }

    /**
     * Integer division rounded half away from zero (no floats anywhere).
     */
    public static function divideRounded(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new \InvalidArgumentException('The denominator must be positive.');
        }

        $sign = $numerator < 0 ? -1 : 1;
        $abs = abs($numerator);

        return $sign * intdiv(2 * $abs + $denominator, 2 * $denominator);
    }
}
