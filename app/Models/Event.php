<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Event extends Model
{
    use HasFactory, HasUuids;

    public const STATUSES = ['draft', 'active', 'ended', 'archived'];

    public const OCCASIONS = ['wedding', 'engagement', 'henna', 'birthday', 'graduation', 'baby_welcome', 'iftar', 'other'];

    protected $fillable = [
        'owner_id', 'org_id', 'anon_session_id', 'occasion', 'title', 'starts_at', 'timezone', 'venue_name',
        'address', 'lat', 'lng', 'hall_layout_id', 'status', 'plan_id', 'guest_limit', 'storage_until',
    ];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'storage_until' => 'date',
            'lat' => 'float',
            'lng' => 'float',
            'guest_limit' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function hallLayout(): BelongsTo
    {
        return $this->belongsTo(HallLayout::class);
    }

    public function invitation(): HasOne
    {
        return $this->hasOne(Invitation::class);
    }

    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }

    public function seatAssignments(): HasMany
    {
        return $this->hasMany(SeatAssignment::class);
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(Checkin::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    public function rose(): HasOne
    {
        return $this->hasOne(Rose::class);
    }

    public function openLink(): HasOne
    {
        return $this->hasOne(OpenLink::class);
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }
}
