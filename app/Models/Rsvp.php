<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rsvp extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['household_id', 'attending', 'people_count', 'kids_count', 'message', 'extras', 'responded_at'];

    protected function casts(): array
    {
        return [
            'attending' => 'boolean',
            'people_count' => 'integer',
            'kids_count' => 'integer',
            'extras' => 'array',
            'responded_at' => 'immutable_datetime',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
