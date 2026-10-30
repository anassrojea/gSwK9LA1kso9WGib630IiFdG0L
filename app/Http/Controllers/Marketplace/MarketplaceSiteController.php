<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\DesignerProfile;
use App\Models\Event;
use App\Models\Opening;
use App\Models\Template;
use App\Models\VendorListing;
use App\Services\Marketplace\MarketplacePrices;
use App\Services\Marketplace\MarketplaceUrl;
use App\Services\Marketplace\VendorListings;
use App\Services\Payments\Currencies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The marketplace's public pages (Blade, SEO first, ar and en like the rest of the site):
 *
 *   /templates             marketplace templates; filters: occasion, opening, price (max, in a currency), designer
 *   /designers/{slug}      an approved designer and their templates on sale
 *   /vendors               approved vendors; filters: city, category, occasion; featured first, badged
 *   /vendors/{slug}        one vendor, JSON-LD LocalBusiness
 *   /go/vendor/{slug}/{whatsapp|instagram}   an anonymous count, then the vendor's link
 */
class MarketplaceSiteController extends Controller
{
    public function __construct(private VendorListings $listings) {}

    public function templates(Request $request): View
    {
        $locale = App::getLocale();
        $filters = $this->templateFilters($request);
        $templates = $this->templateQuery($filters)->paginate(24)->withQueryString();

        return view('marketplace.templates', [
            'seo' => $this->seo('templates', null, __('marketplace.site.meta_title'), __('marketplace.site.meta_description'), $filters['query']),
            'templates' => $templates,
            'cards' => $templates->getCollection()->map(fn (Template $t) => $this->card($t, $filters['currency'], $locale)),
            'filters' => $filters,
            'occasions' => Event::OCCASIONS,
            'openings' => Opening::query()->where('active', true)->orderBy('id')->get(),
            'designers' => DesignerProfile::query()->approved()->whereIn('user_id', Template::query()->onSale()->select('designer_id'))->orderBy('display_name_en')->get(),
            'currencies' => Currencies::all(),
        ]);
    }

    public function designer(DesignerProfile $designer, Request $request): View
    {
        abort_unless($designer->isApproved(), 404);
        $locale = App::getLocale();
        $currency = $this->currency($request);
        $templates = Template::query()->onSale()->where('designer_id', $designer->user_id)->with(['opening', 'designerProfile'])->orderByDesc('published_at')->get();
        abort_if($templates->isEmpty() && ! $request->user()?->isAdmin(), 404);

        return view('marketplace.designer', [
            'seo' => $this->seo('designer', $designer->slug, __('marketplace.site.designer_title', ['name' => $designer->displayName($locale)]), Str::limit((string) ($designer->bio($locale) ?: __('marketplace.site.designer_description', ['name' => $designer->displayName($locale)])), 300)),
            'designer' => $designer,
            'cards' => $templates->map(fn (Template $t) => $this->card($t, $currency, $locale)),
            'currency' => $currency,
        ]);
    }

    public function vendors(Request $request): View
    {
        $filters = [
            'city' => $this->option($request, 'city', array_keys(config('marketplace.cities'))),
            'category' => $this->option($request, 'category', config('marketplace.vendor_categories')),
            'occasion' => $this->option($request, 'occasion', Event::OCCASIONS),
        ];
        $listings = $this->listings->search($filters)->paginate(24)->withQueryString();

        return view('marketplace.vendors', [
            'seo' => $this->seo('vendors', null, $this->vendorsTitle($filters), __('vendors.public.meta_description'), array_filter($filters)),
            'listings' => $listings,
            'filters' => $filters,
            'cities' => array_keys(config('marketplace.cities')),
            'categories' => config('marketplace.vendor_categories'),
            'occasions' => Event::OCCASIONS,
        ]);
    }

    public function vendor(VendorListing $listing): View
    {
        abort_unless($listing->isPublic(), 404);
        $locale = App::getLocale();

        return view('marketplace.vendor', [
            'seo' => $this->seo('vendor', $listing->slug, $listing->name($locale).' · '.__("vendors.categories.{$listing->category}").' · '.__('marketing.brand'), Str::limit((string) ($listing->description($locale) ?: __('vendors.public.vendor_description', ['name' => $listing->name($locale), 'category' => __("vendors.categories.{$listing->category}")])), 300), [], $listing->photoUrls()[0] ?? null),
            'listing' => $listing,
            'featured' => VendorListings::isFeatured($listing),
            'photos' => $listing->photoUrls(),
            'priceRange' => $listing->price_currency && ($listing->price_min !== null || $listing->price_max !== null) ? collect([$listing->price_min, $listing->price_max])->filter(fn ($v) => $v !== null)->map(fn ($v) => Currencies::format((int) $v, $listing->price_currency, $locale))->implode(' – ') : null,
            'calligraphy' => $listing->offersCalligraphy() ? MarketplacePrices::formatted($listing->calligraphy_prices, $locale) : null,
        ]);
    }

    /**
     * GET /go/vendor/{slug}/{channel}: counted without anything about the visitor, then 302.
     */
    public function contact(VendorListing $listing, string $channel): RedirectResponse
    {
        abort_unless($listing->isPublic(), 404);
        $url = $channel === 'whatsapp' ? $listing->whatsappUrl(App::getLocale()) : $listing->instagramUrl();
        abort_unless($url, 404);

        $this->listings->countClick($listing, $channel);

        return redirect()->away($url)->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store');
    }

    /**
     * @return array{occasion: ?string, opening: ?string, designer: ?string, max: ?int, currency: string, query: array<string, mixed>}
     */
    private function templateFilters(Request $request): array
    {
        $currency = $this->currency($request);
        $max = $request->filled('max') && is_numeric($request->query('max')) ? max(0, (int) $request->query('max')) : null;
        $filters = [
            'occasion' => $this->option($request, 'occasion', Event::OCCASIONS),
            'opening' => $this->option($request, 'opening', Opening::query()->pluck('id')->all()),
            'designer' => $request->filled('designer') && preg_match('/^[a-z0-9-]{1,64}$/', (string) $request->query('designer')) ? (string) $request->query('designer') : null,
            'max' => $max,
            'currency' => $currency,
        ];
        $filters['query'] = array_filter([
            'occasion' => $filters['occasion'],
            'opening' => $filters['opening'],
            'designer' => $filters['designer'],
            'max' => $max,
            'currency' => $max !== null ? $currency : null,
        ], fn ($v) => $v !== null);

        return $filters;
    }

    /**
     * @return Builder<Template>
     */
    private function templateQuery(array $filters): Builder
    {
        $currency = $filters['currency'];

        return Template::query()
            ->onSale()
            ->with(['opening', 'designerProfile'])
            ->when($filters['occasion'], fn ($q, $o) => $q->where('occasion', $o))
            ->when($filters['opening'], fn ($q, $o) => $q->where('opening_id', $o))
            ->when($filters['designer'], fn ($q, $slug) => $q->whereHas('designerProfile', fn ($q) => $q->where('slug', $slug)))
            // "up to" a price in the chosen currency: major units in the URL (?max=50&currency=SAR)
            ->when($filters['max'] !== null, fn ($q) => $q->whereRaw('(prices->>?)::bigint <= ?', [$currency, Currencies::toMinor($filters['max'], $currency)]))
            ->orderByDesc('sales_count')
            ->orderByDesc('published_at');
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Template $template, string $currency, string $locale): array
    {
        $price = $template->prices[$currency] ?? null;
        $shown = $price !== null ? $currency : Currencies::fallback();

        return [
            'id' => $template->id,
            'name' => $locale === 'ar' ? $template->name_ar : $template->name_en,
            'description' => $locale === 'ar' ? ($template->description_ar ?: $template->description_en) : ($template->description_en ?: $template->description_ar),
            'occasion' => $template->occasion,
            'opening' => $template->opening ? ($locale === 'ar' ? $template->opening->name_ar : $template->opening->name_en) : null,
            'opening_id' => $template->opening_id,
            'designer' => $template->designerProfile?->displayName($locale),
            'designer_url' => $template->designerProfile ? MarketplaceUrl::path('designer', $locale, $template->designerProfile->slug) : null,
            'price' => isset($template->prices[$shown]) ? Currencies::format((int) $template->prices[$shown], $shown, $locale) : null,
            'price_minor' => $template->prices[$shown] ?? null,
            'price_currency' => $shown,
            'cover' => $template->cover_path ? Storage::disk(config('filesystems.media_disk', 'public'))->url($template->cover_path) : null,
            'colors' => array_slice(array_values(array_filter($template->default_config['theme']['colors'] ?? [], fn ($c) => is_string($c) && preg_match('/^#[0-9a-fA-F]{3,6}$/', $c))), 0, 5),
            'theme' => $template->default_config['theme'] ?? null,
            'signature' => $template->included_in_signature,
        ];
    }

    private function currency(Request $request): string
    {
        $asked = strtoupper((string) $request->query('currency'));
        if (Currencies::supported($asked)) {
            return $asked;
        }
        if ($country = $request->header('CF-IPCountry')) {
            return Currencies::forCountry($country);
        }

        return App::getLocale() === 'ar' && Currencies::supported('SAR') ? 'SAR' : Currencies::fallback();
    }

    /**
     * @param  list<string>  $allowed
     */
    private function option(Request $request, string $key, array $allowed): ?string
    {
        $value = (string) $request->query($key, '');

        return in_array($value, $allowed, true) ? $value : null;
    }

    private function vendorsTitle(array $filters): string
    {
        $parts = array_filter([
            $filters['category'] ? __("vendors.categories_plural.{$filters['category']}") : null,
            $filters['city'] ? __('vendors.public.in_city', ['city' => __("vendors.cities.{$filters['city']}")]) : null,
        ]);

        return ($parts ? implode(' ', $parts) : __('vendors.public.title')).' · '.__('marketing.brand');
    }

    /**
     * The layout's SEO block (resources/views/marketing/layout.blade.php). Filtered lists keep
     * their filters in the canonical URL, so each city/category page is its own indexable page.
     *
     * @return array<string, mixed>
     */
    private function seo(string $page, ?string $param, string $title, string $description, array $query = [], ?string $image = null): array
    {
        return [
            'title' => $title,
            'description' => Str::limit($description, 300),
            'canonical' => MarketplaceUrl::to($page, App::getLocale(), $param, $query),
            'alternates' => MarketplaceUrl::alternates($page, $param, $query),
            'page' => in_array($page, ['templates', 'designer'], true) ? 'templates' : 'vendors',
            'param' => $param,
            'image' => $image ?? asset('images/og-site-'.App::getLocale().'.png'),
        ];
    }
}
