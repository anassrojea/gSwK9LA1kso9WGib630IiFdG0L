<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory, HasUuids;

    public const METHODS = ['card', 'transfer', 'wallet_local'];

    /** awaiting_payment → receipt_received → approved | rejected | expired (48h); card: paid; refunded. */
    public const STATUSES = ['awaiting_payment', 'receipt_received', 'paid', 'approved', 'rejected', 'expired', 'refunded'];

    protected $fillable = ['code', 'user_id', 'event_id', 'org_id', 'items', 'total_cents', 'currency', 'method', 'status', 'expires_at', 'paid_at'];

    protected $attributes = ['status' => 'awaiting_payment'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'total_cents' => 'integer',
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
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

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function transferReceipts(): HasMany
    {
        return $this->hasMany(TransferReceipt::class);
    }
}
