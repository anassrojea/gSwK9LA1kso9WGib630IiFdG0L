<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Checkin extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['event_id', 'household_id', 'scanned_by', 'door_pass_id', 'scan_id', 'people_in', 'scanned_at'];

    protected function casts(): array
    {
        return ['people_in' => 'integer', 'scanned_at' => 'immutable_datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }

    public function doorPass(): BelongsTo
    {
        return $this->belongsTo(DoorPass::class);
    }
}
