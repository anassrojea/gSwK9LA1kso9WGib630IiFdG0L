<?php

namespace App\Services\Payments;

use NumberFormatter;

/**
 * The currencies prices are kept in (config/payments.php): SYP, SAR, USD.
 * Amounts are integers in minor units of each currency's exponent here. SYP
 * uses 0 (whole pounds, as it is priced in practice), SAR and USD use 2.
 * Twin of resources/js/lib/money.ts.
 */
class Currencies
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(config('payments.currencies', []));
    }

    public static function supported(?string $currency): bool
    {
        return $currency !== null && in_array($currency, static::all(), true);
    }

    public static function exponent(string $currency): int
    {
        return (int) (config("payments.currencies.{$currency}.exponent") ?? 2);
    }

    public static function fallback(): string
    {
        return (string) config('payments.fallback_currency', 'USD');
    }

    /**
     * The default currency for a country (ISO 3166 alpha-2): Syria SYP, Saudi Arabia SAR, else USD.
     */
    public static function forCountry(?string $country): string
    {
        return config('payments.countries.'.strtoupper((string) $country)) ?? static::fallback();
    }

    /**
     * A country guessed from the event's time zone, before the host picks one.
     */
    public static function countryForTimezone(?string $timezone): ?string
    {
        return config('payments.timezones')[(string) $timezone] ?? null;
    }

    /**
     * "major" units (109.5) to minor units (10950) for the currency.
     */
    public static function toMinor(float|int|string $major, string $currency): int
    {
        return (int) round(((float) $major) * (10 ** static::exponent($currency)));
    }

    public static function toMajor(int $minor, string $currency): float|int
    {
        $exponent = static::exponent($currency);

        return $exponent === 0 ? $minor : $minor / (10 ** $exponent);
    }

    /**
     * Localized money: "١٠٩ ر.س.‏" in Arabic (Arabic-Indic digits), "SAR 109" or "$29.50" in English.
     * Whole amounts drop the decimals.
     */
    public static function format(int $minor, string $currency, string $locale = 'ar'): string
    {
        $exponent = static::exponent($currency);
        $digits = $exponent > 0 && $minor % (10 ** $exponent) !== 0 ? $exponent : 0;

        $formatter = new NumberFormatter(str_starts_with($locale, 'ar') ? 'ar@numbers=arab' : 'en', NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $digits);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $digits);

        return (string) $formatter->formatCurrency(static::toMajor($minor, $currency), $currency);
    }
}
