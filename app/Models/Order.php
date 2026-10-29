<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * items: the priced lines snapshotted at checkout, never re-read from the price list:
 *   [{type: plan|addon, id, name, quantity, unit_amount, amount}]
 * total_cents: the sum less discount_cents, in minor units of `currency` (App\Services\Payments\Currencies;
 * SYP has no subunit). discount_code / discount_cents: the code used at checkout and what
 * it took off, snapshotted like the lines (App\Services\Payments\Discounts). `currency` is what the gateway charges; `requested_currency`
 * is the host's choice when a card method had to fall back to USD.
 * State changes go through App\Services\Payments\OrderFulfillment.
 */
class Order extends Model
{
    use HasFactory, HasUuids;

    public const METHODS = ['card', 'transfer', 'wallet_local'];

    /** awaiting_payment → receipt_received → approved | rejected | expired (48h); card: paid; refunded. */
    public const STATUSES = ['awaiting_payment', 'receipt_received', 'paid', 'approved', 'rejected', 'expired', 'refunded'];

    /** Waiting for the customer or the admin. */
    public const PENDING = ['awaiting_payment', 'receipt_received'];

    /** Money received: the order's entitlements apply. */
    public const FULFILLED = ['paid', 'approved'];

    protected $fillable = [
        'code', 'user_id', 'event_id', 'org_id', 'items', 'total_cents', 'currency', 'requested_currency', 'method', 'gateway',
        'gateway_ref', 'status', 'expires_at', 'paid_at', 'rejection_reason', 'refunded_at',
        'discount_code_id', 'discount_code', 'discount_cents',
    ];

    protected $attributes = ['status' => 'awaiting_payment', 'gateway' => 'transfer'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'total_cents' => 'integer',
            'discount_cents' => 'integer',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->code ??= static::generateCode();
        });
    }

    /**
     * Short code the customer writes in the transfer note, e.g. DW-4821.
     */
    public static function generateCode(): string
    {
        do {
            $code = 'DW-'.random_int(1000, 999999);
        } while (static::query()->where('code', $code)->exists());

        return $code;
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    /**
     * The lines before the discount.
     */
    public function subtotal(): int
    {
        return $this->total_cents + (int) $this->discount_cents;
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function transferReceipts(): HasMany
    {
        return $this->hasMany(TransferReceipt::class);
    }

    /**
     * The newest receipt (ordered rather than latestOfMany: PostgreSQL has no max(uuid)).
     */
    public function latestReceipt(): HasOne
    {
        return $this->hasOne(TransferReceipt::class)->orderByDesc('received_at')->orderByDesc('created_at');
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class, 'source_order_id');
    }

    public function isTransfer(): bool
    {
        return $this->method === 'transfer';
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING, true);
    }

    public function isFulfilled(): bool
    {
        return in_array($this->status, self::FULFILLED, true);
    }

    /**
     * The plan line of the order, if any.
     */
    public function planId(): ?string
    {
        foreach ($this->items ?? [] as $item) {
            if (($item['type'] ?? null) === 'plan') {
                return $item['id'];
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, quantity: int}>
     */
    public function addonLines(): array
    {
        return collect($this->items ?? [])
            ->where('type', 'addon')
            ->map(fn (array $item) => ['id' => $item['id'], 'quantity' => (int) ($item['quantity'] ?? 1)])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereIn('status', self::PENDING);
    }

    /**
     * @param  Builder<Order>  $query
     */
    public function scopeFulfilled(Builder $query): void
    {
        $query->whereIn('status', self::FULFILLED);
    }
}
