<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * limits: {"guests": int|null, "storage_days": int|null, "features": list<string>} (see App\Services\Entitlements).
 * price_cents/currency: the USD price, used when no USD row exists in prices (App\Services\Payments\PriceList).
 */
class Plan extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'price_cents', 'currency', 'interval', 'limits', 'active'];

    protected function casts(): array
    {
        return ['limits' => 'array', 'price_cents' => 'integer', 'active' => 'boolean'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function prices(): MorphMany
    {
        return $this->morphMany(Price::class, 'priceable');
    }
}
