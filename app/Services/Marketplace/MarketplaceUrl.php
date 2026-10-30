<?php

namespace App\Services\Marketplace;

/**
 * Public marketplace URLs in each language (routes/marketplace.php), like App\Support\SiteUrl:
 * Arabic without a prefix, English under /en.
 *
 *   templates   /templates            designer   /designers/{slug}
 *   vendors     /vendors              vendor     /vendors/{slug}
 */
class MarketplaceUrl
{
    public static function path(string $page, string $locale, ?string $param = null, array $query = []): string
    {
        $path = match ($page) {
            'templates' => '/templates',
            'designer' => '/designers/'.$param,
            'vendors' => '/vendors',
            'vendor' => '/vendors/'.$param,
            default => throw new \InvalidArgumentException("Unknown marketplace page {$page}"),
        };

        $query = array_filter($query, fn ($v) => $v !== null && $v !== '');

        return ($locale === 'en' ? '/en' : '').$path.($query ? '?'.http_build_query($query) : '');
    }

    public static function to(string $page, string $locale, ?string $param = null, array $query = []): string
    {
        return rtrim((string) config('app.url'), '/').static::path($page, $locale, $param, $query);
    }

    /**
     * @return array<string, string>
     */
    public static function alternates(string $page, ?string $param = null, array $query = []): array
    {
        return [
            'ar' => static::to($page, 'ar', $param, $query),
            'en' => static::to($page, 'en', $param, $query),
            'x-default' => static::to($page, 'ar', $param, $query),
        ];
    }
}
