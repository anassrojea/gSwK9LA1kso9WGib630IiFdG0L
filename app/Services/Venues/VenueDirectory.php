<?php

namespace App\Services\Venues;

use App\Models\HallLayout;
use App\Models\OrgMedia;
use App\Models\VenueProfile;
use App\Services\Digits;
use App\Services\Seating\HallLayoutData;
use App\Support\SiteUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;

/**
 * The public venue directory (phase 8B, plan section 13.1): which venues are
 * shown, their public halls, photos and structured data. A venue is public
 * when it opted in (listed), an admin approved it and its organization has
 * the directory_listing feature (VenueFeatures).
 */
class VenueDirectory
{
    public function __construct(private VenueFeatures $features) {}

    /**
     * @return Builder<VenueProfile>
     */
    public function query(): Builder
    {
        return VenueProfile::query()
            ->approved()
            ->whereIn('org_id', $this->features->organizationsWith(VenueFeatures::DIRECTORY_LISTING)->select('id'))
            ->with(['organization:id,name', 'logo']);
    }

    public function isPublic(VenueProfile $profile): bool
    {
        return $profile->listed
            && $profile->status === VenueProfile::STATUS_APPROVED
            && $profile->organization !== null
            && $this->features->has($profile->organization, VenueFeatures::DIRECTORY_LISTING);
    }

    public function find(string $slug): ?VenueProfile
    {
        return $this->query()->where('slug', $slug)->first();
    }

    /**
     * @return Collection<int, HallLayout>
     */
    public function halls(VenueProfile $profile): Collection
    {
        return HallLayout::query()->where('org_id', $profile->org_id)->where('public', true)->orderBy('name')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, OrgMedia>
     */
    public function photos(VenueProfile $profile): Collection
    {
        return OrgMedia::query()->where('org_id', $profile->org_id)->where('kind', OrgMedia::KIND_PHOTO)->orderBy('sort')->orderBy('created_at')->get();
    }

    /** The venue's capacity: as the venue wrote it, else its biggest public hall. */
    public function capacity(VenueProfile $profile, ?Collection $halls = null): ?int
    {
        if ($profile->capacity) {
            return $profile->capacity;
        }
        $max = ($halls ?? $this->halls($profile))->map(fn (HallLayout $h) => HallLayoutData::totalSeats($h->layout ?? []))->max();

        return $max ? (int) $max : null;
    }

    /** A public hall of a public venue, for "Create your invitation at this venue". */
    public function bookableHall(?string $hallId): ?HallLayout
    {
        if (! $hallId || ! preg_match('/^[0-9a-f-]{36}$/i', $hallId)) {
            return null;
        }
        $hall = HallLayout::query()->where('public', true)->whereNotNull('org_id')->find($hallId);
        if (! $hall) {
            return null;
        }
        $profile = VenueProfile::query()->with('organization')->where('org_id', $hall->org_id)->first();

        return $profile && $this->isPublic($profile) ? $hall->setRelation('venueProfile', $profile) : null;
    }

    /**
     * A plural from a {zero, one, two, few, many, other} map (CLDR categories,
     * the same maps the React side reads with tc()), with the count in the
     * page's digits.
     */
    public static function plural(string $key, int $count, ?string $locale = null): string
    {
        $locale ??= App::getLocale();
        $forms = (array) trans($key, [], $locale);
        $mod = $count % 100;
        $category = match (true) {
            $count === 0 && isset($forms['zero']) => 'zero',
            $count === 1 => 'one',
            $locale !== 'ar' => 'other',
            $count === 2 => 'two',
            $mod >= 3 && $mod <= 10 => 'few',
            $mod >= 11 && $mod <= 99 => 'many',
            default => 'other',
        };

        return str_replace(':count', Digits::localize($count, $locale), (string) ($forms[$category] ?? $forms['other'] ?? $key));
    }

    public static function url(VenueProfile $profile, ?string $locale = null): string
    {
        return SiteUrl::to('venue', $locale ?? App::getLocale(), $profile->slug);
    }

    public static function mapUrl(VenueProfile $profile): ?string
    {
        if ($profile->map_url && str_starts_with($profile->map_url, 'https://')) {
            return $profile->map_url;
        }
        if ($profile->lat !== null && $profile->lng !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='.urlencode($profile->lat.','.$profile->lng);
        }

        return null;
    }

    public static function whatsappUrl(VenueProfile $profile, ?string $locale = null): ?string
    {
        $number = $profile->whatsappNumber();
        if (! $number) {
            return null;
        }
        $text = trans('venues.show.whatsapp_text', ['name' => $profile->displayName($locale)], $locale ?? App::getLocale());

        return "https://wa.me/{$number}?text=".rawurlencode($text);
    }

    /**
     * schema.org EventVenue + LocalBusiness for the venue page.
     *
     * @param  Collection<int, OrgMedia>  $photos
     * @return array<string, mixed>
     */
    public function jsonLd(VenueProfile $profile, Collection $photos, ?int $capacity): array
    {
        $locale = App::getLocale();
        $url = static::url($profile, $locale);
        $images = $photos->map(fn (OrgMedia $m) => $m->url())->values()->all();
        if ($logo = $profile->logoUrl()) {
            $images[] = $logo;
        }

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => ['EventVenue', 'LocalBusiness'],
            '@id' => $url.'#venue',
            'name' => $profile->displayName($locale),
            'alternateName' => $locale === 'en' ? $profile->organization?->name : ($profile->name_en ?: null),
            'url' => $url,
            'description' => $profile->description($locale),
            'image' => $images ?: null,
            'logo' => $profile->logoUrl(),
            'address' => array_filter([
                '@type' => 'PostalAddress',
                'addressLocality' => trans("venues.cities.{$profile->city}", [], $locale),
                'streetAddress' => $profile->area,
                'addressCountry' => config("venues.cities.{$profile->city}"),
            ]),
            'geo' => $profile->lat !== null && $profile->lng !== null ? ['@type' => 'GeoCoordinates', 'latitude' => $profile->lat, 'longitude' => $profile->lng] : null,
            'hasMap' => static::mapUrl($profile),
            'maximumAttendeeCapacity' => $capacity,
            'amenityFeature' => collect($profile->amenities ?? [])
                ->filter(fn ($a) => in_array($a, config('venues.amenities'), true))
                ->map(fn ($a) => ['@type' => 'LocationFeatureSpecification', 'name' => trans("venues.amenities.{$a}", [], $locale), 'value' => true])
                ->values()->all() ?: null,
            'telephone' => $profile->whatsappNumber() ? '+'.$profile->whatsappNumber() : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
