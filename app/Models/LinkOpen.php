<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One open of a personal link. Only the token's household and a hash of the
 * user agent are stored: no IPs, no tracking pixels (plan section 8).
 */
class LinkOpen extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['household_id', 'opened_at', 'ua_hash'];

    protected function casts(): array
    {
        return ['opened_at' => 'immutable_datetime'];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
