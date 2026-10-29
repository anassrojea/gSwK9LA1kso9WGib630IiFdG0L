<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A feature unlocked for an event or organization (add-on, grant, plan upgrade). Read through App\Services\Entitlements.
 */
class Entitlement extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['event_id', 'org_id', 'feature', 'until', 'source_order_id'];

    protected function casts(): array
    {
        return ['until' => 'immutable_datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function sourceOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'source_order_id');
    }
}
