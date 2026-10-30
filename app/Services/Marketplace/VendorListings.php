<?php

namespace App\Services\Marketplace;

use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Models\VendorContactClick;
use App\Models\VendorFeature;
use App\Models\VendorListing;
use App\Services\Payments\Currencies;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Vendor listings (plan section 5): a vendor's own listing, the admin's decisions,
 * featured spots, anonymous contact counters, and the lists hosts see.
 *
 * Ordering everywhere: listings featured in the city asked for (any city when none is)
 * first, each with a badge, then the rest by name. A spot ends at ends_at by itself;
 * vendors:end-features closes it for the record, a refund closes it at once.
 */
class VendorListings
{
    /**
     * @param  array<string, mixed>  $data  validated by the vendor portal
     */
    public function save(User $user, array $data): VendorListing
    {
        return DB::transaction(function () use ($user, $data) {
            $listing = VendorListing::query()->where('user_id', $user->getKey())->lockForUpdate()->first() ?? new VendorListing(['user_id' => $user->getKey()]);
            $new = ! $listing->exists;
            $categoryChanged = $listing->exists && $listing->category !== $data['category'];

            if (($data['category'] ?? null) === 'calligraphy') {
                $prices = MarketplacePrices::validate((array) ($data['calligraphy_prices'] ?? []), 'calligraphy_bounds', 'calligraphy_prices');
            }

            $listing->fill([
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'],
                'category' => $data['category'],
                'cities' => array_values(array_unique($data['cities'])),
                'occasions' => array_values(array_unique($data['occasions'] ?? [])) ?: null,
                'description_ar' => $data['description_ar'] ?? null,
                'description_en' => $data['description_en'] ?? null,
                'price_min' => $data['price_min'] ?? null,
                'price_max' => $data['price_max'] ?? null,
                'price_currency' => $data['price_currency'] ?? null,
                'whatsapp' => isset($data['whatsapp']) ? preg_replace('/\D+/', '', $data['whatsapp']) : null,
                'instagram' => isset($data['instagram']) ? ltrim(trim($data['instagram']), '@') : null,
                'calligraphy_prices' => $prices ?? null,
            ]);
            $listing->slug ??= $this->slug($data['name_en'] ?: $data['name_ar']);
            // a changed category is a new listing for the reviewer
            if ($categoryChanged && $listing->status === 'approved') {
                $listing->forceFill(['status' => 'pending']);
            }
            $listing->save();

            AuditLog::record($new ? 'vendor.listing_created' : 'vendor.listing_updated', $listing, [], $user);

            return $listing;
        });
    }

    /** A photo on the media disk under vendors/{listing_id}/. */
    public function addPhoto(VendorListing $listing, UploadedFile $file): string
    {
        $photos = $listing->photos ?? [];
        if (count($photos) >= (int) config('marketplace.max_photos', 8)) {
            throw ValidationException::withMessages(['photo' => trans('vendors.errors.too_many_photos', ['count' => config('marketplace.max_photos', 8)])]);
        }

        $path = $file->storeAs("vendors/{$listing->getKey()}", Str::lower(Str::random(16)).'.'.($file->guessExtension() ?: 'jpg'), ['disk' => config('filesystems.media_disk', 'public')]);
        $listing->update(['photos' => [...$photos, $path]]);

        return $path;
    }

    public function removePhoto(VendorListing $listing, int $index): void
    {
        $photos = $listing->photos ?? [];
        if (! isset($photos[$index])) {
            return;
        }
        Storage::disk(config('filesystems.media_disk', 'public'))->delete($photos[$index]);
        unset($photos[$index]);
        $listing->update(['photos' => array_values($photos)]);
    }

    public function approve(VendorListing $listing, User $admin): void
    {
        $this->decide($listing, $admin, 'approved', null);
    }

    public function reject(VendorListing $listing, User $admin, string $reason): void
    {
        $this->decide($listing, $admin, 'rejected', $reason);
    }

    public function hide(VendorListing $listing, User $admin, ?string $reason = null): void
    {
        $this->decide($listing, $admin, 'hidden', $reason);
    }

    /**
     * "Featured in {city}/{category}" for $days from now (or from the end of a spot still running there).
     */
    public function feature(VendorListing $listing, string $city, int $days, ?Order $order = null, ?User $admin = null): VendorFeature
    {
        if ($admin && ! $admin->isAdmin()) {
            throw new AuthorizationException(trans('app.payments.errors.admins_only'));
        }

        $running = $listing->features()->active()->where('city', $city)->orderByDesc('ends_at')->first();
        $from = $running?->ends_at ?? CarbonImmutable::now();

        $feature = $listing->features()->create([
            'city' => $city,
            'category' => $listing->category,
            'starts_at' => $running ? $from : now(),
            'ends_at' => $from->addDays($days),
            'source_order_id' => $order?->getKey(),
            'granted_by' => $admin?->getKey(),
        ]);

        AuditLog::record('vendor.featured', $listing, ['city' => $city, 'days' => $days, 'ends_at' => $feature->ends_at->toIso8601String(), 'order' => $order?->code], $admin);

        return $feature;
    }

    public function endFeaturesOf(Order $order): int
    {
        return VendorFeature::query()->where('source_order_id', $order->getKey())->whereNull('ended_at')->update(['ended_at' => now()]);
    }

    public function endExpiredFeatures(): int
    {
        $ended = 0;
        VendorFeature::query()->whereNull('ended_at')->where('ends_at', '<=', now())->with('listing')->each(function (VendorFeature $feature) use (&$ended) {
            $feature->update(['ended_at' => $feature->ends_at]);
            AuditLog::record('vendor.feature_ended', $feature->listing ?? $feature, ['city' => $feature->city, 'ends_at' => $feature->ends_at->toIso8601String()]);
            $ended++;
        });

        return $ended;
    }

    /**
     * One anonymous tap on WhatsApp / Instagram: a daily counter, nothing about the visitor.
     */
    public function countClick(VendorListing $listing, string $channel): void
    {
        DB::statement(
            'insert into vendor_contact_clicks (listing_id, channel, day, count) values (?, ?, ?, 1)
             on conflict (listing_id, channel, day) do update set count = vendor_contact_clicks.count + 1',
            [$listing->getKey(), $channel, now()->toDateString()],
        );
    }

    /**
     * @return array<string, int> channel => clicks (last $days days)
     */
    public function clicks(VendorListing $listing, int $days = 30): array
    {
        return VendorContactClick::query()
            ->where('listing_id', $listing->getKey())
            ->where('day', '>=', now()->subDays($days)->toDateString())
            ->groupBy('channel')
            ->selectRaw('channel, sum(count) as total')
            ->pluck('total', 'channel')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Public listings, featured first.
     *
     * @param  array{city?: ?string, category?: ?string, occasion?: ?string}  $filters
     * @return Builder<VendorListing>
     */
    public function search(array $filters): Builder
    {
        $city = $filters['city'] ?? null;

        return VendorListing::query()
            ->public()
            ->when($city, fn ($q) => $q->whereJsonContains('cities', $city))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($filters['occasion'] ?? null, fn ($q, $o) => $q->where(fn ($q) => $q->whereNull('occasions')->orWhereJsonContains('occasions', $o)))
            ->withExists(['features as featured' => fn ($q) => $q->active()->when($city, fn ($q) => $q->where('city', $city))])
            ->orderByDesc('featured')
            ->orderBy(app()->getLocale() === 'ar' ? 'name_ar' : 'name_en');
    }

    /**
     * "Vendors near your venue" on the host dashboard: the event's city (guessed from its
     * address or venue name, else its time zone's country) and occasion.
     *
     * @return Collection<int, VendorListing>
     */
    public function forEvent(Event $event, int $limit = 4): Collection
    {
        $city = static::cityFor($event);
        $country = Currencies::countryForTimezone($event->timezone);

        $query = $this->search(['city' => $city, 'occasion' => $event->occasion]);
        if (! $city && $country) {
            $cities = array_keys(array_filter(config('marketplace.cities'), fn ($c) => $c === $country));
            $query->where(fn ($q) => collect($cities)->each(fn ($c) => $q->orWhereJsonContains('cities', $c)));
        }

        return $query->limit($limit)->get();
    }

    /** A city key from config('marketplace.cities') named in the event's address or venue. */
    public static function cityFor(Event $event): ?string
    {
        $haystack = mb_strtolower(trim(($event->address ?? '').' '.($event->venue_name ?? '')));
        if ($haystack === '') {
            return null;
        }

        foreach (array_keys(config('marketplace.cities')) as $city) {
            foreach ([trans("vendors.cities.{$city}", [], 'ar'), trans("vendors.cities.{$city}", [], 'en'), $city] as $name) {
                if ($name !== '' && str_contains($haystack, mb_strtolower($name))) {
                    return $city;
                }
            }
        }

        return null;
    }

    /** Is this listing featured now (in $city, or anywhere)? */
    public static function isFeatured(VendorListing $listing, ?string $city = null): bool
    {
        return $listing->features()->active()->when($city, fn ($q) => $q->where('city', $city))->exists();
    }

    private function decide(VendorListing $listing, User $admin, string $status, ?string $reason): void
    {
        if (! $admin->isAdmin()) {
            throw new AuthorizationException(trans('app.payments.errors.admins_only'));
        }
        if ($status === 'rejected' && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => trans('validation.required', ['attribute' => 'reason'])]);
        }

        $listing->forceFill([
            'status' => $status,
            'status_reason' => $reason,
            'approved_at' => $status === 'approved' ? ($listing->approved_at ?? now()) : $listing->approved_at,
        ])->save();
        AuditLog::record("vendor.{$status}", $listing, array_filter(['reason' => $reason]), $admin);
    }

    private function slug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'vendor', 60, '');
        $slug = $base;
        while (VendorListing::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }
}
