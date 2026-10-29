<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The customer's "I sent the receipt" (received_via whatsapp), then the admin's decision:
 * approved_by (who decided, also for rejections), decided_at, decision, the bank reference
 * typed on approval (unique: one bank transfer approves one order) and the rejection reason.
 */
class TransferReceipt extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['order_id', 'received_via', 'received_at', 'note', 'approved_by', 'decided_at', 'decision', 'bank_reference', 'reason'];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
