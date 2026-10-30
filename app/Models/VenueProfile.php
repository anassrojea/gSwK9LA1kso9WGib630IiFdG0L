<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/**
 * A venue's page in the directory (phase 8B, plan section 13.1): one per
 * organization. Public at /venues/{slug} when the venue opts in (listed),
 * an admin approved it (status) and the organization has the
 * directory_listing feature (App\Services\Venues\VenueDirectory).
 */
class VenueProfile extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_HIDDEN];

    protected $fillable = [
        'org_id', 'slug', 'name_en', 'venue_type', 'city', 'area', 'description_ar', 'description_en', 'capacity',
        'amenities', 'whatsapp', 'map_url', 'lat', 'lng', 'logo_id', 'listed', 'status', 'featured', 'approved_at', 'approved_by',
    ];

    protected $attributes = ['status' => self::STATUS_DRAFT, 'listed' => false, 'featured' => false, 'venue_type' => 'hall', 'amenities' => '[]'];

    protected function casts(): array
    {
        return [
            'amenities' => 'array',
            'listed' => 'boolean',
            'featured' => 'boolean',
            'capacity' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
            'approved_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VenueProfile $profile) {
            $profile->qr_code ??= static::generateCode();
        });
    }

    /** The short code of the venue's QR poster (/v/{code}): 8 base62 characters. */
    public static function generateCode(): string
    {
        do {
            $code = Str::random(8);
        } while (static::query()->where('qr_code', $code)->exists());

        return $code;
    }

    /** A free page address from a name ("Al Noor Hall" → "al-noor-hall", then "-2"…). */
    public static function uniqueSlug(string $name, ?string $except = null): string
    {
        $base = Str::limit(Str::slug($name), 70, '') ?: 'venue';
        if (strlen($base) < 3) {
            $base = 'venue-'.$base;
        }
        $slug = $base;
        for ($i = 2; static::query()->where('slug', $slug)->when($except, fn ($q) => $q->whereKeyNot($except))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function logo(): BelongsTo
    {
        return $this->belongsTo(OrgMedia::class, 'logo_id');
    }

    /** @param  Builder<VenueProfile>  $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('listed', true)->where('status', self::STATUS_APPROVED);
    }

    /** The venue's name in the page language: the organization name, or the English name on English pages. */
    public function displayName(?string $locale = null): string
    {
        $locale ??= App::getLocale();
        $name = (string) $this->organization?->name;

        return $locale === 'en' && $this->name_en ? $this->name_en : $name;
    }

    public function description(?string $locale = null): ?string
    {
        $locale ??= App::getLocale();

        return $locale === 'en' ? ($this->description_en ?: $this->description_ar) : ($this->description_ar ?: $this->description_en);
    }

    /**
     * The WhatsApp number: the profile's own, else the organization's contact
     * phone (organizations.contact_phone, phase 8A).
     */
    public function whatsappNumber(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($this->whatsapp ?: $this->organization?->getAttribute('contact_phone')));

        return $digits !== '' && strlen($digits) >= 8 ? $digits : null;
    }

    /**
     * The logo: the one uploaded on the profile (org_media), else the
     * organization's white-label logo (organizations.logo_path, phase 8A).
     */
    public function logoUrl(): ?string
    {
        if ($this->logo) {
            return $this->logo->url();
        }
        $path = $this->organization?->getAttribute('logo_path');

        return is_string($path) && $path !== '' ? (str_starts_with($path, 'https://') ? $path : OrgMedia::disk()->url($path)) : null;
    }

    /** The organization's own city (organizations.city, phase 8A), when it is one of the directory's cities. */
    public static function orgCity(Organization $org): ?string
    {
        $city = $org->getAttribute('city');

        return is_string($city) && array_key_exists($city, config('venues.cities')) ? $city : null;
    }
}
