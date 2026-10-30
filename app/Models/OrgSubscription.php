<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's Venue & Planner Pro subscription (phase 8A). One row per
 * subscription, at most one live (trialing | active | past_due) per org.
 * provider: stripe (mirrors the Cashier subscription "org:{org_id}" on the
 * payer user) | lemon_squeezy | transfer (renewal orders approved by an admin).
 * State changes go through App\Services\Org\OrgSubscriptions.
 */
class OrgSubscription extends Model
{
    use HasUuids;

    public const STATUSES = ['trialing', 'active', 'past_due', 'canceled', 'expired'];

    /** The org's plan applies. */
    public const LIVE = ['trialing', 'active', 'past_due'];

    public const PROVIDERS = ['stripe', 'lemon_squeezy', 'transfer'];

    protected $fillable = [
        'org_id', 'user_id', 'plan_id', 'interval', 'provider', 'provider_id', 'provider_customer_id', 'provider_price',
        'currency', 'amount_cents', 'status', 'current_period_start', 'current_period_end', 'cancel_at_period_end',
        'next_plan_id', 'past_due_since', 'canceled_at', 'ended_at', 'reminders',
    ];

    protected $attributes = ['cancel_at_period_end' => false];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'cancel_at_period_end' => 'boolean',
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'past_due_since' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'reminders' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }

    public function isTransfer(): bool
    {
        return $this->provider === 'transfer';
    }

    /** The Cashier subscription type on the payer user. */
    public static function stripeType(Organization|string $org): string
    {
        return 'org:'.($org instanceof Organization ? $org->getKey() : $org);
    }

    /**
     * @param  Builder<OrgSubscription>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', self::LIVE);
    }
}
