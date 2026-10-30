<?php

namespace App\Console\Commands;

use App\Services\Org\OrgSubscriptions;
use Illuminate\Console\Command;

/**
 * Bank-transfer subscriptions (phase 8A): the renewal order (DW-…, 48 h for
 * the receipt) for each period ending within 7 days, once per period, with
 * an email to the owner. Idempotent. Scheduled in routes/console.php.
 */
class OrgRenewalOrders extends Command
{
    protected $signature = 'org:renewal-orders';

    protected $description = 'Create the transfer renewal orders of organization subscriptions';

    public function handle(OrgSubscriptions $subscriptions): int
    {
        $this->info('Created '.$subscriptions->createRenewalOrders().' renewal order(s).');

        return self::SUCCESS;
    }
}
