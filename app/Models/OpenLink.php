<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The event's open link (plan section 8): /i/{token} for everyone, with a
 * cap on the total people. Replies become households tagged "open-link".
 */
class OpenLink extends Model
{
    use HasUuids;

    /** Longer than household tokens (12), which lets the rate limiter tell the two apart without a query. */
    public const TOKEN_LENGTH = 16;

    /** Tag on the households created by open-link replies. */
    public const TAG = 'open-link';

    /** People one reply can bring. */
    public const MAX_PER_REPLY = 10;

    public const MAX_CAP = 10000;

    protected $fillable = ['event_id', 'token', 'enabled', 'cap'];

    protected $attributes = ['enabled' => false, 'open_count' => 0];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'cap' => 'integer',
            'open_count' => 'integer',
            'first_opened_at' => 'immutable_datetime',
            'last_opened_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OpenLink $link) {
            $link->token ??= static::generateToken();
        });
    }

    /**
     * Random base62 token, unique across open links and household tokens
     * (both are served at /i/{token}).
     */
    public static function generateToken(): string
    {
        do {
            $token = Str::random(self::TOKEN_LENGTH);
        } while (
            static::query()->where('token', $token)->exists()
            || Household::query()->where('token', $token)->exists()
        );

        return $token;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Households created by replies through this link.
     *
     * @return HasMany<Household, $this>
     */
    public function households(): HasMany
    {
        return $this->hasMany(Household::class, 'event_id', 'event_id')->whereRaw('? = any(households.tags)', [self::TAG]);
    }

    /**
     * People confirmed through the link (attending replies only), optionally
     * leaving out one household (the reply being changed).
     */
    public function peopleCount(?string $exceptHousehold = null): int
    {
        return (int) Rsvp::query()
            ->join('households', 'households.id', '=', 'rsvps.household_id')
            ->where('households.event_id', $this->event_id)
            ->whereRaw('? = any(households.tags)', [self::TAG])
            ->where('rsvps.attending', true)
            ->when($exceptHousehold, fn ($q) => $q->where('households.id', '!=', $exceptHousehold))
            ->sum('rsvps.people_count');
    }

    public function seatsLeft(?string $exceptHousehold = null): int
    {
        return max(0, $this->cap - $this->peopleCount($exceptHousehold));
    }
}
