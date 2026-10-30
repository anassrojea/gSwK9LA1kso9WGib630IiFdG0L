<?php

namespace App\Services\Marketplace;

use App\Services\Payments\Currencies;
use Illuminate\Validation\ValidationException;

/**
 * Prices a designer (or calligrapher) sets per currency, in minor units, within the
 * bounds an admin configures (config/marketplace.php). SYP is allowed; USD is required
 * because card gateways charge USD when they can't take the host's currency.
 */
class MarketplacePrices
{
    /**
     * @param  array<string, mixed>  $prices  {"USD": 900, "SAR": 3400, "SYP": null}
     * @param  string  $bounds  config key under marketplace.*: price_bounds | calligraphy_bounds
     * @return array<string, int> the prices that are set
     *
     * @throws ValidationException keyed prices.{CURRENCY}
     */
    public static function validate(array $prices, string $bounds = 'price_bounds', string $field = 'prices'): array
    {
        $clean = [];
        $errors = [];

        foreach ($prices as $currency => $amount) {
            $currency = strtoupper((string) $currency);
            if ($amount === null || $amount === '') {
                continue;
            }
            if (! Currencies::supported($currency)) {
                $errors["{$field}.{$currency}"] = trans('marketplace.errors.currency');

                continue;
            }
            if (! is_numeric($amount) || (int) $amount != $amount) {
                $errors["{$field}.{$currency}"] = trans('marketplace.errors.price_integer');

                continue;
            }
            [$min, $max] = config("marketplace.{$bounds}.{$currency}", [0, PHP_INT_MAX]);
            $amount = (int) $amount;
            if ($amount < $min || $amount > $max) {
                $errors["{$field}.{$currency}"] = trans('marketplace.errors.price_bounds', [
                    'min' => Currencies::format($min, $currency, app()->getLocale()),
                    'max' => Currencies::format($max, $currency, app()->getLocale()),
                ]);

                continue;
            }
            $clean[$currency] = $amount;
        }

        foreach ((array) config('marketplace.required_price_currencies', []) as $currency) {
            if (! isset($clean[$currency]) && ! isset($errors["{$field}.{$currency}"])) {
                $errors["{$field}.{$currency}"] = trans('marketplace.errors.price_required', ['currency' => $currency]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        ksort($clean);

        return $clean;
    }

    /**
     * @return array<string, array{min: int, max: int, exponent: int}>
     */
    public static function bounds(string $bounds = 'price_bounds'): array
    {
        return collect(Currencies::all())
            ->mapWithKeys(function (string $c) use ($bounds) {
                [$min, $max] = config("marketplace.{$bounds}.{$c}", [0, 0]);

                return [$c => ['min' => (int) $min, 'max' => (int) $max, 'exponent' => Currencies::exponent($c)]];
            })
            ->all();
    }

    /**
     * Formatted prices for display, in the currencies set: "١٢ $ · ٤٥ ر.س.".
     *
     * @param  array<string, int>|null  $prices
     * @return array<string, string>
     */
    public static function formatted(?array $prices, string $locale): array
    {
        return collect($prices ?? [])->filter()->mapWithKeys(fn ($amount, $c) => [$c => Currencies::format((int) $amount, $c, $locale)])->all();
    }
}
