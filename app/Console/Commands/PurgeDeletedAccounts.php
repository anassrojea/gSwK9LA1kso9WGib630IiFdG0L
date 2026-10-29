<?php

namespace App\Console\Commands;

use App\Models\LoginCode;
use App\Models\User;
use App\Services\Account\AccountDeletion;
use Illuminate\Console\Command;

/**
 * Deletes the accounts whose deletion request is older than the grace period
 * (config/account.php), and clears old one-time codes. Scheduled daily
 * (routes/console.php); see App\Services\Account\AccountDeletion for what is
 * kept and what goes.
 */
class PurgeDeletedAccounts extends Command
{
    protected $signature = 'account:purge-deleted';

    protected $description = 'Delete accounts whose deletion grace period is over';

    public function handle(AccountDeletion $deletion): int
    {
        $purged = 0;
        $postponed = 0;

        $deletion->due()->orderBy('deletion_requested_at')->each(function (User $user) use ($deletion, &$purged, &$postponed) {
            $deletion->purge($user) ? $purged++ : $postponed++;
        });

        LoginCode::query()->where('expires_at', '<', now()->subDay())->delete();

        $this->info("Deleted {$purged} account(s), postponed {$postponed}.");

        return self::SUCCESS;
    }
}
