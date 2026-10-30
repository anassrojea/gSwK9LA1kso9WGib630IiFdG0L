<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Signature event published with a marketplace template the designer included in
 * Signature. Only recorded for now: the monthly pool that pays these is not built yet
 * (docs/MARKETPLACE.md).
 */
class MarketplaceSignatureUse extends Model
{
    use HasUuids;

    protected $fillable = ['template_id', 'designer_id', 'event_id', 'plan_id', 'period'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
