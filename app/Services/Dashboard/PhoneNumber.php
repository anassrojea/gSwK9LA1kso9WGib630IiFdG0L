<?php

namespace App\Services\Dashboard;

use App\Services\Digits;

/**
 * Phone numbers as hosts type them: "+962 79 123 4567", "٠٧٩١٢٣٤٥٦٧",
 * "00966-55-123-4567". Stored with Western digits, an optional leading "+",
 * and no spaces or punctuation.
 */
class PhoneNumber
{
    public const PERSIAN = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

    /** What a host may type: digits in any script, spaces, + - . ( ) and direction marks */
    private const PATTERN = '/^[+\d٠-٩۰-۹\s\-.()\x{200E}\x{200F}]+$/u';

    public static function western(string $value): string
    {
        return Digits::toWestern(strtr($value, self::PERSIAN));
    }

    public static function normalize(?string $phone): ?string
    {
        $phone = trim(self::western((string) $phone), " \t\n\r\0\x0B'");
        if ($phone === '') {
            return null;
        }

        $plus = str_starts_with(ltrim($phone, "\u{200E}\u{200F}"), '+');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits === '' ? null : ($plus ? '+' : '').$digits;
    }

    public static function valid(?string $phone): bool
    {
        $phone = trim((string) $phone, " \t\n\r\0\x0B'");
        if ($phone === '') {
            return true;
        }

        $digits = preg_replace('/\D+/', '', self::western($phone)) ?? '';

        return preg_match(self::PATTERN, $phone) === 1 && strlen($digits) >= 6 && strlen($digits) <= 15;
    }

    /**
     * Digits only, in international form without "+" or "00" (what wa.me expects).
     */
    public static function forWhatsApp(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', self::western((string) $phone)) ?? '';
        $digits = preg_replace('/^00/', '', $digits) ?? $digits;

        return strlen($digits) >= 6 ? $digits : null;
    }
}
