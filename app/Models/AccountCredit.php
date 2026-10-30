<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a user's credit ledger (phase 8D): rewards add, spending at
 * checkout subtracts, a refund restores. See App\Services\Journey\Credits.
 */
class AccountCredit extends Model
{
    use HasUuids;

    public const REFERRAL_REWARD = 'referral_reward';

    public const SPEND = 'spend';

    public const RESTORE = 'restore';

    public const ADMIN = 'admin';

    protected $fillable = ['user_id', 'currency', 'amount', 'kind', 'referral_id', 'order_id', 'note'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }
}
