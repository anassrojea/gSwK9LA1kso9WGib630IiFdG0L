<?php

namespace App\Support;

/**
 * URLs of the public site in each language (routes/marketing.php). Arabic is
 * the default and has no prefix; English lives under /en. Canonical and
 * hreflang URLs always use APP_URL, never the short invite domain.
 */
class SiteUrl
{
    public const PAGES = ['home', 'pricing', 'about', 'occasion', 'legal'];

    public const LEGAL = ['terms', 'privacy', 'refund', 'cookies'];

    public static function path(string $page, string $locale, ?string $param = null): string
    {
        $path = match ($page) {
            'home' => '',
            'pricing' => '/pricing',
            'about' => '/about',
            'occasion' => '/occasions/'.$param,
            'legal' => '/legal/'.$param,
            default => throw new \InvalidArgumentException("Unknown site page {$page}"),
        };

        $prefix = $locale === 'en' ? '/en' : '';

        return ($prefix.$path) ?: '/';
    }

    public static function to(string $page, string $locale, ?string $param = null): string
    {
        return rtrim((string) config('app.url'), '/').static::path($page, $locale, $param);
    }

    /**
     * hreflang alternates: ar, en and x-default (Arabic).
     *
     * @return array<string, string>
     */
    public static function alternates(string $page, ?string $param = null): array
    {
        return [
            'ar' => static::to($page, 'ar', $param),
            'en' => static::to($page, 'en', $param),
            'x-default' => static::to($page, 'ar', $param),
        ];
    }

    /**
     * The landing page link at the end of every guest invitation (plan section 14.7),
     * through the anonymous click counter.
     */
    public static function designYours(): string
    {
        return rtrim((string) config('app.url'), '/').'/r/design-yours';
    }
}
