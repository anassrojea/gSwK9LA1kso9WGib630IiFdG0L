<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row of the append-only designer ledger (App\Services\Marketplace\DesignerLedger).
 * Never updated or deleted: a correction is a new posting.
 */
class DesignerLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    public const ACCOUNTS = ['sales', 'designer_payable', 'platform_share', 'payouts_cash'];

    protected $fillable = ['txn_id', 'designer_id', 'kind', 'account', 'currency', 'debit_cents', 'credit_cents', 'order_id', 'template_id', 'payout_id', 'memo'];

    protected function casts(): array
    {
        return ['debit_cents' => 'integer', 'credit_cents' => 'integer', 'memo' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('The designer ledger is append-only.'));
        static::deleting(fn () => throw new LogicException('The designer ledger is append-only.'));
    }

    public function designer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'designer_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }
}
