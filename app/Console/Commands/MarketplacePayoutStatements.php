<?php

namespace App\Console\Commands;

use App\Services\Marketplace\PayoutStatements;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Monthly designer payout statements per currency (docs/MARKETPLACE.md), for the month
 * before by default. Scheduled in MarketplaceServiceProvider on the 1st of each month.
 */
class MarketplacePayoutStatements extends Command
{
    protected $signature = 'marketplace:payout-statements {--month= : YYYY-MM (default: last month)}';

    protected $description = 'Issue the designers\' monthly payout statements (above the minimum, per currency)';

    public function handle(PayoutStatements $statements): int
    {
        $month = $this->option('month')
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $this->option('month'))
            : CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();

        if (! $month) {
            $this->error('Use --month=YYYY-MM.');

            return self::INVALID;
        }

        $issued = $statements->run($month);
        $this->info('Issued '.count($issued).' statement(s) for '.$month->format('Y-m').'.');

        return self::SUCCESS;
    }
}
