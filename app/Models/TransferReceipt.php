<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferReceipt extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['order_id', 'received_via', 'received_at', 'note', 'approved_by', 'decided_at', 'decision'];

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
