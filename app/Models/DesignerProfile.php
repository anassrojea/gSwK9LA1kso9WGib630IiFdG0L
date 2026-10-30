<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's application to sell templates (plan section 2, "Designer"): pending until an
 * admin approves it in Filament, suspended to stop sales. payout_details are encrypted.
 * status and commission_bp are not fillable: only App\Services\Marketplace\Designers changes them.
 */
class DesignerProfile extends Model
{
    use HasUuids;

    public const STATUSES = ['pending', 'approved', 'suspended'];

    protected $fillable = [
        'user_id', 'slug', 'display_name_ar', 'display_name_en', 'bio_ar', 'bio_en', 'portfolio_links',
        'payout_details', 'country', 'terms_accepted_at', 'terms_version',
    ];

    protected $attributes = ['status' => 'pending'];

    protected $hidden = ['payout_details'];

    protected function casts(): array
    {
        return [
            'portfolio_links' => 'array',
            'payout_details' => 'encrypted:array',
            'commission_bp' => 'integer',
            'terms_accepted_at' => 'immutable_datetime',
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

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class, 'designer_id', 'user_id');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /** The designer's share in basis points (7000 = 70 %). */
    public function shareBp(): int
    {
        return $this->commission_bp ?? (int) config('marketplace.designer_share_bp', 7000);
    }

    public function displayName(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->display_name_ar : $this->display_name_en;
    }

    public function bio(?string $locale = null): ?string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? ($this->bio_ar ?: $this->bio_en) : ($this->bio_en ?: $this->bio_ar);
    }

    /**
     * @param  Builder<DesignerProfile>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', 'approved');
    }
}
