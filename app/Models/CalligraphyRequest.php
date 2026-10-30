<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A host's custom calligraphy name logo (plan section 14.6), bought as the order item
 * calligraphy_request: awaiting_payment → new (paid) → in_progress → delivered, tracked by
 * the calligrapher; cancelled when the order is refunded.
 */
class CalligraphyRequest extends Model
{
    use HasUuids;

    public const STATUSES = ['awaiting_payment', 'new', 'in_progress', 'delivered', 'cancelled'];

    /** What the calligrapher can move a paid request to. */
    public const VENDOR_STATUSES = ['in_progress', 'delivered'];

    protected $fillable = ['listing_id', 'user_id', 'event_id', 'order_id', 'names', 'brief', 'status', 'vendor_note', 'delivered_at'];

    protected $attributes = ['status' => 'awaiting_payment'];

    protected function casts(): array
    {
        return ['delivered_at' => 'immutable_datetime'];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(VendorListing::class, 'listing_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
