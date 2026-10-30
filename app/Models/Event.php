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

    /** Every occasion of config schema v2 (v1 has the first seven and "other"). */
    public const OCCASIONS = [
        'wedding', 'engagement', 'henna', 'birthday', 'graduation', 'baby_welcome', 'iftar',
        'katb_kitab', 'eid', 'housewarming', 'hajj_return', 'welcome_home', 'corporate', 'save_the_date', 'thank_you', 'other',
    ];

    /** The wizard's occasion tiles: a thank-you card is made from an event after it (phase 8D), never from scratch. */
    public const WIZARD_OCCASIONS = [
        'wedding', 'engagement', 'katb_kitab', 'henna', 'birthday', 'graduation', 'baby_welcome', 'iftar',
        'eid', 'housewarming', 'hajj_return', 'welcome_home', 'corporate', 'save_the_date', 'other',
    ];

    protected $fillable = [
        'owner_id', 'org_id', 'anon_session_id', 'occasion', 'title', 'starts_at', 'timezone', 'venue_name',
        'address', 'lat', 'lng', 'hall_layout_id', 'status', 'plan_id', 'guest_limit', 'storage_until', 'messages', 'album',
        'live_stream_url',
    ];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'storage_until' => 'date',
            // phase 6B, App\Services\Storage\StorageExpiry: {"until": "Y-m-d", "sent": {"14": iso, "3": iso}}
            'storage_warnings' => 'array',
            'media_purged_at' => 'immutable_datetime',
            'lat' => 'float',
            'lng' => 'float',
            'guest_limit' => 'integer',
            'messages' => 'array',
            'album' => 'array',
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

    public function doorPasses(): HasMany
    {
        return $this->hasMany(DoorPass::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function rose(): HasOne
    {
        return $this->hasOne(Rose::class);
    }

    public function openLink(): HasOne
    {
        return $this->hasOne(OpenLink::class);
    }

    public function guestbook(): HasMany
    {
        return $this->hasMany(GuestbookEntry::class);
    }

    public function aiGenerations(): HasMany
    {
        return $this->hasMany(AiGeneration::class);
    }

    /** Phase 8D: the save-the-date this invitation grew from, its thank-you card and keepsakes. */
    public function sourceEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'source_event_id');
    }

    public function thankYouCard(): HasOne
    {
        return $this->hasOne(ThankYouCard::class);
    }

    public function highlightVideos(): HasMany
    {
        return $this->hasMany(HighlightVideo::class);
    }

    public function photoBooks(): HasMany
    {
        return $this->hasMany(PhotoBook::class);
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }
}
