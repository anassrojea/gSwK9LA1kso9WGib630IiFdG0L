<?php

namespace App\Models;

use App\Casts\PgTextArray;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A family / guest group with its own personal link (/i/{token}) and seat limit.
 */
class Household extends Model
{
    use HasFactory, HasUuids;

    public const TOKEN_LENGTH = 12;

    protected $fillable = ['event_id', 'display_name', 'phone', 'seats_allowed', 'section', 'token', 'tags'];

    protected $attributes = ['seats_allowed' => 1, 'open_count' => 0];

    protected function casts(): array
    {
        return [
            'tags' => PgTextArray::class,
            'seats_allowed' => 'integer',
            'open_count' => 'integer',
            'first_opened_at' => 'immutable_datetime',
            'last_opened_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Household $household) {
            $household->token ??= static::generateToken();
            $household->tags ??= [];
        });
    }

    /**
     * Random, unguessable base62 token (plan section 8): 62^12 ≈ 3×10^21.
     */
    public static function generateToken(): string
    {
        do {
            $token = Str::random(self::TOKEN_LENGTH);
        } while (static::query()->where('token', $token)->exists());

        return $token;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function rsvp(): HasOne
    {
        return $this->hasOne(Rsvp::class);
    }

    public function linkOpens(): HasMany
    {
        return $this->hasMany(LinkOpen::class);
    }

    public function seatAssignments(): HasMany
    {
        return $this->hasMany(SeatAssignment::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(Checkin::class);
    }

    public function walletPasses(): HasMany
    {
        return $this->hasMany(WalletPass::class);
    }
}
