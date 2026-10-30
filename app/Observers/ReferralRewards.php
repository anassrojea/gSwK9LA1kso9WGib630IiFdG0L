<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\Journey\Referrals;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Throwable;

/**
 * The referred user's first paid order was paid or approved: credit the
 * referrer (App\Services\Journey\Referrals::orderFulfilled). Runs after the
 * payment commits, so a referral problem never undoes a payment; the
 * referral's status makes it run once. Registered in JourneyServiceProvider.
 */
class ReferralRewards implements ShouldHandleEventsAfterCommit
{
    public function __construct(private Referrals $referrals) {}

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status') || ! $order->isFulfilled()) {
            return;
        }

        try {
            $this->referrals->orderFulfilled($order);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
