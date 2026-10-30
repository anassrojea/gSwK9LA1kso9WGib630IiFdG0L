<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CalligraphyRequest;
use App\Models\Event;
use App\Models\VendorFeature;
use App\Models\VendorListing;
use App\Services\Marketplace\MarketplacePrices;
use App\Services\Marketplace\MarketplaceUrl;
use App\Services\Marketplace\VendorListings;
use App\Services\Payments\Currencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The vendor portal (/vendor-portal): any signed-in user creates their listing (one per
 * account); an admin approves it; then featured spots and, for calligraphers, the name
 * logo requests hosts paid for. A vendor only reaches their own listing and requests.
 */
class VendorPortalController extends Controller
{
    public function __construct(private VendorListings $listings) {}

    public function show(Request $request): Response
    {
        $listing = $request->user()->vendorListing()->first();
        $locale = App::getLocale();

        return Inertia::render('vendors/Portal', [
            'listing' => $listing ? [
                'slug' => $listing->slug,
                'status' => $listing->status,
                'status_reason' => $listing->status_reason,
                'name_ar' => $listing->name_ar,
                'name_en' => $listing->name_en,
                'category' => $listing->category,
                'cities' => $listing->cities ?? [],
                'occasions' => $listing->occasions ?? [],
                'description_ar' => $listing->description_ar ?? '',
                'description_en' => $listing->description_en ?? '',
                'price_min' => $listing->price_min,
                'price_max' => $listing->price_max,
                'price_currency' => $listing->price_currency,
                'whatsapp' => $listing->whatsapp ?? '',
                'instagram' => $listing->instagram ?? '',
                'calligraphy_prices' => (object) ($listing->calligraphy_prices ?? []),
                'photos' => $listing->photoUrls(),
                'public_url' => $listing->isPublic() ? MarketplaceUrl::path('vendor', $locale, $listing->slug) : null,
                'clicks' => $this->listings->clicks($listing),
                'features' => $listing->features()->orderByDesc('ends_at')->limit(10)->get()->map(fn (VendorFeature $f) => [
                    'city' => $f->city,
                    'starts_at' => $f->starts_at->toIso8601String(),
                    'ends_at' => $f->ends_at->toIso8601String(),
                    'active' => $f->ended_at === null && $f->starts_at->isPast() && $f->ends_at->isFuture(),
                ])->values(),
                'requests' => $listing->calligraphyRequests()->where('status', '!=', 'awaiting_payment')->latest()->limit(50)->get()->map(fn (CalligraphyRequest $r) => [
                    'id' => $r->id,
                    'names' => $r->names,
                    'brief' => $r->brief,
                    'status' => $r->status,
                    'vendor_note' => $r->vendor_note,
                    'created_at' => $r->created_at?->toIso8601String(),
                    'customer' => $r->user?->name,
                ])->values(),
            ] : null,
            'options' => [
                'categories' => config('marketplace.vendor_categories'),
                'cities' => array_keys(config('marketplace.cities')),
                'occasions' => Event::OCCASIONS,
                'currencies' => Currencies::all(),
                'calligraphy_bounds' => MarketplacePrices::bounds('calligraphy_bounds'),
                'feature_price' => MarketplacePrices::formatted(config('marketplace.feature.prices'), $locale),
                'max_photos' => (int) config('marketplace.max_photos', 8),
            ],
            'urls' => [
                'save' => route('vendor-portal.save', absolute: false),
                'photos' => route('vendor-portal.photos.store', absolute: false),
                'feature' => route('buy.feature', absolute: false),
            ],
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(config('marketplace.vendor_categories'))],
            'cities' => ['required', 'array', 'min:1', 'max:12'],
            'cities.*' => ['string', Rule::in(array_keys(config('marketplace.cities')))],
            'occasions' => ['nullable', 'array'],
            'occasions.*' => ['string', Rule::in(Event::OCCASIONS)],
            'description_ar' => ['nullable', 'string', 'max:3000'],
            'description_en' => ['nullable', 'string', 'max:3000'],
            'price_min' => ['nullable', 'integer', 'min:0'],
            'price_max' => ['nullable', 'integer', 'min:0', 'gte:price_min'],
            'price_currency' => ['nullable', 'required_with:price_min,price_max', Rule::in(Currencies::all())],
            'whatsapp' => ['nullable', 'string', 'regex:/^\+?[0-9 ]{8,20}$/'],
            'instagram' => ['nullable', 'string', 'regex:/^@?[A-Za-z0-9._]{1,30}$/'],
            'calligraphy_prices' => ['nullable', 'array'],
            'calligraphy_prices.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $this->listings->save($request->user(), $data);

        return redirect()->route('vendor-portal.show')->with('status', trans('vendors.portal.saved'));
    }

    public function addPhoto(Request $request): RedirectResponse
    {
        $listing = $this->own($request);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:'.config('marketplace.max_upload_kb', 4096)]]);
        $this->listings->addPhoto($listing, $request->file('photo'));

        return back()->with('status', trans('vendors.portal.photo_added'));
    }

    public function removePhoto(Request $request, int $index): RedirectResponse
    {
        $this->listings->removePhoto($this->own($request), $index);

        return back();
    }

    /**
     * PATCH /vendor-portal/calligraphy/{calligraphy} {status: in_progress|delivered, vendor_note?}
     */
    public function calligraphy(Request $request, CalligraphyRequest $calligraphy): RedirectResponse
    {
        $listing = $this->own($request);
        abort_unless($calligraphy->listing_id === $listing->getKey(), 403);
        abort_if(in_array($calligraphy->status, ['awaiting_payment', 'cancelled'], true), 422);

        $data = $request->validate([
            'status' => ['required', Rule::in(CalligraphyRequest::VENDOR_STATUSES)],
            'vendor_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $calligraphy->update($data + ['delivered_at' => $data['status'] === 'delivered' ? now() : null]);
        AuditLog::record('calligraphy.status', $calligraphy, ['status' => $data['status']], $request->user());

        return back()->with('status', trans('vendors.portal.request_updated'));
    }

    private function own(Request $request): VendorListing
    {
        $listing = $request->user()->vendorListing()->first();
        abort_unless($listing, 404);

        return $listing;
    }
}
