<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received for an order: provider transfer | stripe | lemon_squeezy, provider_ref unique
 * per provider (webhook retries can't record a payment twice), raw: the provider's payload.
 * No card data is ever stored (plan section 20).
 */
class Payment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['order_id', 'provider', 'provider_ref', 'amount_cents', 'currency', 'status', 'raw'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'raw' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
