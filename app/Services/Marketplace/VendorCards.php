<?php

namespace App\Services\Marketplace;

use App\Models\Event;
use App\Models\VendorListing;
use Illuminate\Support\Facades\App;

/**
 * The host dashboard's "Vendors near your venue" card (phase 8C): a few approved vendors
 * for the event's city and occasion, featured ones first. Contact goes through the
 * anonymous counter straight to WhatsApp.
 */
class VendorCards
{
    public function __construct(private VendorListings $listings) {}

    /**
     * @return array{city: ?string, city_name: ?string, items: list<array<string, mixed>>, all_url: string}|null
     */
    public function forEvent(Event $event): ?array
    {
        if ($event->isArchived()) {
            return null;
        }

        $locale = App::getLocale();
        $city = VendorListings::cityFor($event);
        $items = $this->listings->forEvent($event)->map(fn (VendorListing $l) => [
            'slug' => $l->slug,
            'name' => $l->name($locale),
            'category' => $l->category,
            'featured' => (bool) $l->featured,
            'photo' => $l->photoUrls()[0] ?? null,
            'url' => MarketplaceUrl::path('vendor', $locale, $l->slug),
            'whatsapp' => $l->whatsapp ? route('vendors.contact', [$l, 'whatsapp'], false) : null,
        ])->values()->all();

        if ($items === []) {
            return null;
        }

        return [
            'city' => $city,
            'city_name' => $city ? trans("vendors.cities.{$city}") : null,
            'items' => $items,
            'all_url' => MarketplaceUrl::path('vendors', $locale, null, ['city' => $city, 'occasion' => $event->occasion]),
        ];
    }
}
