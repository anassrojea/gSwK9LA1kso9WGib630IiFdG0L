<?php

namespace App\Console\Commands;

use App\Services\Marketplace\VendorListings;
use Illuminate\Console\Command;

/**
 * Featured spots past their 30 days stop being shown first (docs/MARKETPLACE.md).
 * Scheduled hourly in MarketplaceServiceProvider; the public pages also ignore expired
 * spots on their own, so this only closes them for the record.
 */
class EndVendorFeatures extends Command
{
    protected $signature = 'vendors:end-features';

    protected $description = 'End vendor featured spots that are over';

    public function handle(VendorListings $listings): int
    {
        $ended = $listings->endExpiredFeatures();
        $this->info("Ended {$ended} featured spot(s).");

        return self::SUCCESS;
    }
}
