<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\HallLayout;
use App\Models\Organization;
use App\Models\OrgMedia;
use App\Models\SiteCounter;
use App\Models\VenueProfile;
use App\Services\Analytics\Analytics;
use App\Services\OpenTracker;
use App\Services\Seating\HallLayoutData;
use App\Services\Venues\VenueDirectory;
use App\Support\SiteUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The public venue directory (phase 8B, plan section 13.1), part of the
 * public site in Arabic (/venues) and English (/en/venues):
 *
 *   GET /venues?city=&type=&q=   approved venues, featured first
 *   GET /venues/{slug}           one venue: photos, halls with a plan, amenities,
 *                                WhatsApp, map, "Create your invitation at this venue"
 *   GET /v/{code}                the venue's QR poster: an anonymous scan count,
 *                                then the venue page with utm_source=venue_qr
 */
class VenueDirectoryController extends Controller
{
    public const SCAN_COUNTER = 'venue_qr';

    public function __construct(private VenueDirectory $directory) {}

    public function index(Request $request): View
    {
        $locale = App::getLocale();
        $cities = array_keys(config('venues.cities'));
        $types = config('venues.types');

        $city = in_array($request->query('city'), $cities, true) ? $request->query('city') : null;
        $type = in_array($request->query('type'), $types, true) ? $request->query('type') : null;
        $q = Str::limit(trim((string) $request->query('q')), 60, '');

        $venues = $this->directory->query()
            ->when($city, fn ($query) => $query->where('city', $city))
            ->when($type, fn ($query) => $query->where('venue_type', $type))
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
                $query->where(fn ($w) => $w->where('area', 'ilike', $like)
                    ->orWhere('name_en', 'ilike', $like)
                    ->orWhereHas('organization', fn ($o) => $o->where('name', 'ilike', $like)));
            })
            ->orderByDesc('featured')
            ->orderBy(
                Organization::query()->select('name')->whereColumn('organizations.id', 'venue_profiles.org_id')
            )
            ->paginate((int) config('venues.per_page'))
            ->withQueryString();

        $covers = OrgMedia::query()
            ->whereIn('org_id', $venues->pluck('org_id'))
            ->where('kind', OrgMedia::KIND_PHOTO)
            ->orderBy('sort')->orderBy('created_at')
            ->get()
            ->unique('org_id')
            ->keyBy('org_id');
        $halls = HallLayout::query()->whereIn('org_id', $venues->pluck('org_id'))->where('public', true)->get()->groupBy('org_id');

        $cards = $venues->getCollection()->map(fn (VenueProfile $v) => [
            'name' => $v->displayName($locale),
            'url' => SiteUrl::path('venue', $locale, $v->slug),
            'city' => __("venues.cities.{$v->city}"),
            'area' => $v->area,
            'type' => __("venues.types.{$v->venue_type}"),
            'capacity' => $this->directory->capacity($v, $halls[$v->org_id] ?? collect()),
            'image' => ($covers[$v->org_id] ?? null)?->url() ?? $v->logoUrl(),
            'logo' => $v->logoUrl(),
            'featured' => $v->featured,
        ]);

        $title = $city ? __('venues.meta.city_title', ['city' => __("venues.cities.{$city}")]) : __('venues.meta.index_title');
        $seo = $this->seo('venues', null, $title, __('venues.meta.index_description'));
        if ($city && ! $type && $q === '' && $venues->currentPage() === 1) {
            // a city page is worth indexing on its own
            $seo['canonical'] .= '?city='.$city;
            $seo['alternates'] = array_map(fn ($href) => $href.'?city='.$city, $seo['alternates']);
        }

        return view('marketing.venues.index', [
            'seo' => $seo,
            'noindex' => $type !== null || $q !== '' || $venues->currentPage() > 1,
            'venues' => $venues,
            'cards' => $cards,
            'filters' => ['city' => $city, 'type' => $type, 'q' => $q],
            'cities' => $cities,
            'types' => $types,
            'itemList' => [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'name' => $title,
                'itemListElement' => $cards->values()->map(fn ($c, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'url' => rtrim((string) config('app.url'), '/').$c['url']])->all(),
            ],
        ]);
    }

    public function show(string $venue): View
    {
        $profile = $this->directory->find($venue) ?? abort(404);
        $locale = App::getLocale();

        $halls = $this->directory->halls($profile);
        $photos = $this->directory->photos($profile);
        $capacity = $this->directory->capacity($profile, $halls);
        $name = $profile->displayName($locale);
        $city = __("venues.cities.{$profile->city}");

        $seo = $this->seo('venue', $profile->slug, __('venues.meta.show_title', ['name' => $name, 'city' => $city]), $profile->description($locale) ?: __('venues.meta.show_description', ['name' => $name, 'city' => $city]));
        if ($image = $photos->first()?->url() ?? $profile->logoUrl()) {
            $seo['image'] = $image;
        }

        return view('marketing.venues.show', [
            'seo' => $seo,
            'profile' => $profile,
            'name' => $name,
            'city' => $city,
            'photos' => $photos,
            'halls' => $halls->map(fn (HallLayout $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'layout' => $h->layout,
                'tables' => count($h->layout['tables'] ?? []),
                'seats' => HallLayoutData::totalSeats($h->layout ?? []),
            ]),
            'capacity' => $capacity,
            'amenities' => array_values(array_intersect(config('venues.amenities'), $profile->amenities ?? [])),
            'whatsapp' => VenueDirectory::whatsappUrl($profile, $locale),
            'map' => VenueDirectory::mapUrl($profile),
            'jsonld' => $this->directory->jsonLd($profile, $photos, $capacity),
            'breadcrumbs' => [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => __('marketing.nav.home'), 'item' => SiteUrl::to('home', $locale)],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => __('venues.index.title'), 'item' => SiteUrl::to('venues', $locale)],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $name, 'item' => SiteUrl::to('venue', $locale, $profile->slug)],
                ],
            ],
        ]);
    }

    /**
     * GET /v/{code}: the QR on the venue's reception poster. Counts the scan
     * per venue, anonymously (no cookie, IP or id is kept), then opens the
     * venue page in Arabic with UTM parameters.
     */
    public function scan(Request $request, string $code, Analytics $analytics): RedirectResponse
    {
        $profile = VenueProfile::query()->where('qr_code', $code)->first();
        $public = $profile && $this->directory->isPublic($profile);

        if ($profile && ! static::isBot($request->userAgent())) {
            SiteCounter::bump(self::SCAN_COUNTER, $profile->getKey());
            $analytics->captureAnonymous('venue_qr_scanned', ['city' => $profile->city, 'listed' => $public]);
        }

        $query = http_build_query(['utm_source' => 'venue_qr', 'utm_medium' => 'poster', 'utm_campaign' => 'venue']);
        $target = $public ? SiteUrl::to('venue', 'ar', $profile->slug) : SiteUrl::to('home', 'ar');

        return redirect()->away($target.'?'.$query)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public static function posterUrl(VenueProfile $profile): string
    {
        return rtrim((string) config('app.url'), '/').'/v/'.$profile->qr_code;
    }

    private static function isBot(?string $userAgent): bool
    {
        return $userAgent === null
            || Str::contains($userAgent, OpenTracker::CRAWLERS, ignoreCase: true)
            || Str::contains($userAgent, ['bot', 'crawler', 'spider', 'preview'], ignoreCase: true);
    }

    /**
     * @return array{title: string, description: string, canonical: string, alternates: array<string, string>, page: string, param: ?string, image: string}
     */
    private function seo(string $page, ?string $param, string $title, string $description): array
    {
        return [
            'title' => $title,
            'description' => Str::limit(trim(preg_replace('/\s+/u', ' ', $description) ?? ''), 300),
            'canonical' => SiteUrl::to($page, App::getLocale(), $param),
            'alternates' => SiteUrl::alternates($page, $param),
            'page' => $page,
            'param' => $param,
            'image' => asset('images/og-site-'.App::getLocale().'.png'),
        ];
    }
}
