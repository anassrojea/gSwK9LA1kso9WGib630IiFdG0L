<?php

namespace App\Services\Journey;

use App\Models\AccountCredit;
use App\Models\Order;
use App\Models\User;
use App\Services\Payments\Currencies;

/**
 * Account credit (phase 8D, plan section 14.7): a ledger per user and
 * currency (account_credits). Referral rewards add to it; at checkout the
 * host can pay part or all of an order with it (line "referral_credit",
 * ReferralCreditItem), which is taken from the balance when the order is paid
 * and given back on a refund. While an order is unpaid its credit line is
 * held, so the same credit can't be spent twice.
 */
class Credits
{
    public function balance(User $user, string $currency): int
    {
        return (int) AccountCredit::query()->where('user_id', $user->getKey())->where('currency', $currency)->sum('amount');
    }

    /**
     * The balance less what unpaid orders already hold.
     */
    public function available(User $user, string $currency): int
    {
        $held = 0;
        Order::query()->where('user_id', $user->getKey())->where('currency', $currency)->pending()->get(['id', 'items'])
            ->each(function (Order $order) use (&$held) {
                foreach ($order->items ?? [] as $line) {
                    if (($line['type'] ?? null) === 'referral_credit') {
                        $held += abs((int) $line['amount']);
                    }
                }
            });

        return max(0, $this->balance($user, $currency) - $held);
    }

    /**
     * @return array<string, int> currency => available amount, for every currency
     */
    public function availableAll(User $user): array
    {
        return collect(Currencies::all())->mapWithKeys(fn (string $c) => [$c => $this->available($user, $c)])->all();
    }

    public function add(User|string $user, string $currency, int $amount, string $kind, array $attributes = []): AccountCredit
    {
        return AccountCredit::query()->create([
            'user_id' => $user instanceof User ? $user->getKey() : $user,
            'currency' => $currency,
            'amount' => $amount,
            'kind' => $kind,
        ] + $attributes);
    }

    /**
     * The order was paid: its credit line leaves the balance (once).
     */
    public function spend(Order $order, int $amount): void
    {
        if ($amount <= 0 || AccountCredit::query()->where('order_id', $order->getKey())->where('kind', AccountCredit::SPEND)->exists()) {
            return;
        }

        $this->add($order->user_id, $order->currency, -$amount, AccountCredit::SPEND, ['order_id' => $order->getKey()]);
    }

    /**
     * The order was refunded: the credit it used comes back (once).
     */
    public function restore(Order $order, int $amount): void
    {
        if ($amount <= 0
            || ! AccountCredit::query()->where('order_id', $order->getKey())->where('kind', AccountCredit::SPEND)->exists()
            || AccountCredit::query()->where('order_id', $order->getKey())->where('kind', AccountCredit::RESTORE)->exists()) {
            return;
        }

        $this->add($order->user_id, $order->currency, $amount, AccountCredit::RESTORE, ['order_id' => $order->getKey()]);
    }
}
