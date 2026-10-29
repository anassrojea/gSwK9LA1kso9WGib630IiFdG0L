<?php

namespace App\Services;

/**
 * Arabic-Indic digits in Arabic invitations, Western digits in English ones (plan section 6.4).
 */
class Digits
{
    private const ARABIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    public static function localize(string|int|float $value, ?string $locale = null): string
    {
        $value = (string) $value;
        $locale ??= app()->getLocale();

        return str_starts_with($locale, 'ar')
            ? strtr($value, array_combine(range(0, 9), self::ARABIC))
            : self::toWestern($value);
    }

    public static function toWestern(string $value): string
    {
        return strtr($value, array_combine(self::ARABIC, array_map('strval', range(0, 9))));
    }

    /**
     * "20:30" → "٨:٣٠ م" (ar) or "8:30 PM" (en).
     */
    public static function time(string $hhmm, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if (! preg_match('/^(\d{1,2}):(\d{2})$/', self::toWestern($hhmm), $m)) {
            return self::localize($hhmm, $locale);
        }

        $hour = (int) $m[1] % 24;
        $suffix = $hour < 12 ? __('invite.am', [], $locale) : __('invite.pm', [], $locale);
        $h12 = $hour % 12 === 0 ? 12 : $hour % 12;

        return self::localize("{$h12}:{$m[2]}", $locale).' '.$suffix;
    }
}
