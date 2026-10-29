<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'media';

    protected $fillable = ['event_id', 'household_id', 'kind', 'storage_key', 'thumb_key', 'width', 'height', 'bytes', 'hidden'];

    protected function casts(): array
    {
        return ['hidden' => 'boolean', 'width' => 'integer', 'height' => 'integer', 'bytes' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
