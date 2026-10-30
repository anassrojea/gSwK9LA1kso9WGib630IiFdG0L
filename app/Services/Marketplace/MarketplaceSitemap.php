<?php

namespace App\Services\Marketplace;

use App\Models\DesignerProfile;
use App\Models\Template;
use App\Models\VendorListing;

/**
 * sitemap.xml entries for the marketplace (tagged "sitemap.entries", read by SeoController):
 * the template and vendor directories, approved designers with templates on sale, and
 * approved vendor listings.
 */
class MarketplaceSitemap
{
    /**
     * @return iterable<array{page: string, param: ?string, changefreq: string, priority: string, lastmod?: ?string}>
     */
    public function entries(): iterable
    {
        yield ['page' => 'templates', 'param' => null, 'changefreq' => 'daily', 'priority' => '0.7'];
        yield ['page' => 'vendors', 'param' => null, 'changefreq' => 'daily', 'priority' => '0.7'];

        $designers = Template::query()->onSale()->distinct()->pluck('designer_id');
        foreach (DesignerProfile::query()->approved()->whereIn('user_id', $designers)->orderBy('slug')->get(['slug', 'updated_at']) as $profile) {
            yield ['page' => 'designer', 'param' => $profile->slug, 'changefreq' => 'weekly', 'priority' => '0.5', 'lastmod' => $profile->updated_at?->toDateString()];
        }

        foreach (VendorListing::query()->public()->orderBy('slug')->get(['slug', 'updated_at']) as $listing) {
            yield ['page' => 'vendor', 'param' => $listing->slug, 'changefreq' => 'weekly', 'priority' => '0.5', 'lastmod' => $listing->updated_at?->toDateString()];
        }
    }

    /**
     * Each entry as the sitemap writes it: a URL per language and its hreflang alternates.
     *
     * @return iterable<array{locs: array<string, string>, alternates: array<string, string>, changefreq: string, priority: string, lastmod: ?string}>
     */
    public function urls(): iterable
    {
        foreach ($this->entries() as $entry) {
            yield [
                'locs' => ['ar' => MarketplaceUrl::to($entry['page'], 'ar', $entry['param']), 'en' => MarketplaceUrl::to($entry['page'], 'en', $entry['param'])],
                'alternates' => MarketplaceUrl::alternates($entry['page'], $entry['param']),
                'changefreq' => $entry['changefreq'],
                'priority' => $entry['priority'],
                'lastmod' => $entry['lastmod'] ?? null,
            ];
        }
    }
}
