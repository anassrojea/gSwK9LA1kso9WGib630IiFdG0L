<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * limits: {"guests": int|null, "storage_days": int|null, "features": list<string>} (see App\Services\Entitlements).
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
}
