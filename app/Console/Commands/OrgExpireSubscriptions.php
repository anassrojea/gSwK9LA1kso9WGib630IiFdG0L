<?php

namespace App\Console\Commands;

use App\Services\Org\OrgSubscriptions;
use Illuminate\Console\Command;

/**
 * Organization subscriptions (phase 8A): transfer periods that ended unpaid
 * become past_due (email to the owner), or canceled when they were cancelled
 * at period end; anything past_due longer than the grace period
 * (config('org.grace_days')) expires and the organization loses its plan.
 * Idempotent. Scheduled in routes/console.php.
 */
class OrgExpireSubscriptions extends Command
{
    protected $signature = 'org:expire-subscriptions';

    protected $description = 'Move unpaid organization subscriptions to past_due, then expired after the grace period';

    public function handle(OrgSubscriptions $subscriptions): int
    {
        $overdue = $subscriptions->markOverdue();
        $expired = $subscriptions->expireOverdue();

        $this->info("{$overdue} past due or ended, {$expired} expired.");

        return self::SUCCESS;
    }
}
