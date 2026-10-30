<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A vendor's public listing (plan section 5: photographers, kosha designers, bands…):
 * pending until an admin approves it; hidden by an admin to take it down.
 * status is not fillable: App\Services\Marketplace\VendorListings changes it.
 */
class VendorListing extends Model
{
    use HasUuids;

    public const STATUSES = ['pending', 'approved', 'rejected', 'hidden'];

    protected $fillable = [
        'user_id', 'slug', 'name_ar', 'name_en', 'category', 'cities', 'occasions', 'description_ar', 'description_en',
        'photos', 'price_min', 'price_max', 'price_currency', 'whatsapp', 'instagram', 'calligraphy_prices',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'cities' => 'array',
            'occasions' => 'array',
            'photos' => 'array',
            'calligraphy_prices' => 'array',
            'price_min' => 'integer',
            'price_max' => 'integer',
            'approved_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function features(): HasMany
    {
        return $this->hasMany(VendorFeature::class, 'listing_id');
    }

    public function calligraphyRequests(): HasMany
    {
        return $this->hasMany(CalligraphyRequest::class, 'listing_id');
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : $this->name_en;
    }

    public function description(?string $locale = null): ?string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? ($this->description_ar ?: $this->description_en) : ($this->description_en ?: $this->description_ar);
    }

    public function isPublic(): bool
    {
        return $this->status === 'approved';
    }

    public function offersCalligraphy(): bool
    {
        return $this->category === 'calligraphy' && array_filter($this->calligraphy_prices ?? []) !== [];
    }

    /**
     * @return list<string>
     */
    public function photoUrls(): array
    {
        $disk = Storage::disk(config('filesystems.media_disk', 'public'));

        return array_values(array_map(fn (string $path) => $disk->url($path), $this->photos ?? []));
    }

    /** wa.me link with a greeting in the visitor's language. */
    public function whatsappUrl(?string $locale = null): ?string
    {
        $number = preg_replace('/\D+/', '', (string) $this->whatsapp);
        if (! $number) {
            return null;
        }

        return 'https://wa.me/'.$number.'?text='.rawurlencode(trans('vendors.public.whatsapp_message', ['name' => $this->name($locale)], $locale));
    }

    public function instagramUrl(): ?string
    {
        $handle = ltrim((string) $this->instagram, '@');

        return $handle !== '' ? 'https://instagram.com/'.rawurlencode($handle) : null;
    }

    /**
     * @param  Builder<VendorListing>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->where('status', 'approved');
    }
}
