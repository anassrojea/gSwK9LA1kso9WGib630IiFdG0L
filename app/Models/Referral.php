<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sign-up that came through someone's /r/{code} (phase 8D, plan section 14.7).
 * reward_status: pending → rewarded | capped; blocked (self-referral) | void.
 * See App\Services\Journey\Referrals.
 */
class Referral extends Model
{
    use HasFactory, HasUuids;

    public const PENDING = 'pending';

    public const REWARDED = 'rewarded';

    public const CAPPED = 'capped';

    public const BLOCKED = 'blocked';

    public const VOID = 'void';

    protected $fillable = ['referrer_user_id', 'referred_user_id', 'reward_status', 'code', 'ip', 'reason', 'rewarded_at', 'reward_order_id'];

    protected $attributes = ['reward_status' => self::PENDING];

    protected function casts(): array
    {
        return ['rewarded_at' => 'immutable_datetime'];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function rewardOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'reward_order_id');
    }
}
