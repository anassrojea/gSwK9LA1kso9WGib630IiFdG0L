<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The yearly anniversary email for an event was sent (journey:anniversaries): one row per year.
 */
class AnniversaryReminder extends Model
{
    public $timestamps = false;

    protected $fillable = ['event_id', 'year', 'sent_at'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'sent_at' => 'immutable_datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
