<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletPass extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['household_id', 'platform', 'serial', 'pass_url'];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
