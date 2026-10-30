<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A monthly payout statement for one designer in one currency (marketplace:payout-statements),
 * paid by bank transfer and marked paid with its reference in Filament (PayoutResource).
 */
class DesignerPayout extends Model
{
    use HasUuids;

    protected $fillable = ['designer_id', 'currency', 'period', 'amount_cents', 'sales_cents', 'refunds_cents', 'sales_count'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'sales_cents' => 'integer',
            'refunds_cents' => 'integer',
            'sales_count' => 'integer',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function designer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'designer_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
