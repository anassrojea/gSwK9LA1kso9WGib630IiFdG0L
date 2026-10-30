<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Anonymous daily counter of contact taps on a vendor listing (no visitor data).
 */
class VendorContactClick extends Model
{
    public $timestamps = false;

    protected $fillable = ['listing_id', 'channel', 'day', 'count'];

    protected function casts(): array
    {
        return ['day' => 'date', 'count' => 'integer'];
    }
}
