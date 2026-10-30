<?php

namespace App\Console\Commands;

use App\Services\Org\OrgSubscriptions;
use Illuminate\Console\Command;

/**
 * Reminder emails 7 days and 1 day before a bank-transfer subscription's
 * period ends (phase 8A). Each one is sent once per period. Scheduled in
 * routes/console.php.
 */
class OrgRenewalReminders extends Command
{
    protected $signature = 'org:renewal-reminders';

    protected $description = 'Remind organizations that their transfer subscription renews soon';

    public function handle(OrgSubscriptions $subscriptions): int
    {
        $this->info('Sent '.$subscriptions->sendReminders().' reminder(s).');

        return self::SUCCESS;
    }
}
