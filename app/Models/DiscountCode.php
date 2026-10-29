<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discount code (plan section 4.3), applied at checkout by
 * App\Services\Payments\Discounts. Either `percent` (1–100) or `amounts`
 * per currency in minor units ({"SAR": 2000}); a fixed code only applies to
 * an order charged in a currency it has an amount for. Uses are the paid or
 * approved orders that carry it (never stored, so refunds give them back).
 */
class DiscountCode extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'code', 'description', 'percent', 'amounts', 'applies_to_plans', 'applies_to_addons', 'plan_ids', 'addon_ids',
        'starts_at', 'ends_at', 'max_uses', 'max_uses_per_user', 'active',
    ];

    protected $attributes = ['applies_to_plans' => true, 'applies_to_addons' => true, 'active' => true];

    protected function casts(): array
    {
        return [
            'percent' => 'integer',
            'amounts' => 'array',
            'applies_to_plans' => 'boolean',
            'applies_to_addons' => 'boolean',
            'plan_ids' => 'array',
            'addon_ids' => 'array',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'max_uses' => 'integer',
            'max_uses_per_user' => 'integer',
            'active' => 'boolean',
        ];
    }

    public static function normalize(?string $code): string
    {
        return mb_strtoupper(trim((string) $code));
    }

    protected function code(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => static::normalize($value));
    }

    public function isPercent(): bool
    {
        return $this->percent !== null;
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<DiscountCode>  $query
     */
    public function scopeWithUses(Builder $query): void
    {
        $query->withCount(['orders as uses_count' => fn ($q) => $q->whereIn('status', Order::FULFILLED)]);
    }
}
