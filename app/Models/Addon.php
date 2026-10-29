<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * An add-on (plan section 13.2). What it unlocks: config/payments.php "addons".
 * price_cents/currency: the USD fallback price (App\Services\Payments\PriceList).
 */
class Addon extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'price_cents', 'currency', 'active'];

    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'active' => 'boolean'];
    }

    public function prices(): MorphMany
    {
        return $this->morphMany(Price::class, 'priceable');
    }
}
