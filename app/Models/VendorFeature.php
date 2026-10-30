<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Featured in {city}/{category}" from starts_at to ends_at: listed first with a badge.
 * vendors:end-features sets ended_at once it is over; a refund ends it at once.
 */
class VendorFeature extends Model
{
    use HasUuids;

    protected $fillable = ['listing_id', 'city', 'category', 'starts_at', 'ends_at', 'ended_at', 'source_order_id', 'granted_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(VendorListing::class, 'listing_id');
    }

    /**
     * @param  Builder<VendorFeature>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('ended_at')->where('starts_at', '<=', now())->where('ends_at', '>', now());
    }
}
