<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event-plan credit from a bundle: pays one event's plan (plan_id) at
 * checkout before expires_at (App\Services\Journey\BundleCredits).
 */
class BundleCredit extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'bundle_id', 'plan_id', 'source_order_id', 'expires_at', 'used_at', 'used_event_id', 'used_order_id', 'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function usedEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'used_event_id');
    }

    public function isAvailable(): bool
    {
        return $this->used_at === null && $this->voided_at === null && $this->expires_at->isFuture();
    }

    /**
     * Not used, not voided, not expired.
     *
     * @param  Builder<BundleCredit>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->whereNull('used_at')->whereNull('voided_at')->where('expires_at', '>', now());
    }
}
