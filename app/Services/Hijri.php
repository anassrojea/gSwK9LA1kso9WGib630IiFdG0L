<?php

namespace App\Services;

use DateTimeInterface;
use IntlDateFormatter;

/**
 * Hijri (Umm al-Qura) dates via ICU, the server-side twin of
 * Intl.DateTimeFormat('ar-SA-u-ca-islamic-umalqura'). Hosts can override the
 * result because sightings can differ by a day (plan section 6.4).
 */
class Hijri
{
    public static function format(DateTimeInterface $date, string $locale = 'ar', ?string $timezone = null): string
    {
        $formatter = new IntlDateFormatter(
            ($locale === 'ar' ? 'ar_SA' : 'en_US').'@calendar=islamic-umalqura',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $timezone ?? $date->getTimezone()->getName(),
            IntlDateFormatter::TRADITIONAL,
            'd MMMM y',
        );

        $suffix = $locale === 'ar' ? ' هـ' : ' AH';

        return Digits::localize($formatter->format($date), $locale).$suffix;
    }
}
