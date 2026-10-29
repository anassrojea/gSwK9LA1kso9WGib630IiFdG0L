<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A plan's or add-on's price in one currency, in minor units of the exponent
 * in config/payments.php (App\Services\Payments\Currencies). Read through
 * App\Services\Payments\PriceList.
 */
class Price extends Model
{
    use HasUuids;

    protected $fillable = ['priceable_type', 'priceable_id', 'currency', 'amount', 'placeholder'];

    protected $attributes = ['placeholder' => false];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'placeholder' => 'boolean'];
    }

    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }
}
