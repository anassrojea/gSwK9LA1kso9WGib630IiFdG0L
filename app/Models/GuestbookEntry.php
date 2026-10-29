<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestbookEntry extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'guestbook';

    protected $fillable = ['event_id', 'household_id', 'kind', 'text', 'media_id'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
