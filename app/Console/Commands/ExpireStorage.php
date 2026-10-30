<?php

namespace App\Console\Commands;

use App\Services\Storage\StorageExpiry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily (routes/console.php): storage warnings 14 and 3 days ahead, media
 * purge after events.storage_until, archive after the grace period
 * (App\Services\Storage\StorageExpiry). --dry-run lists what would happen.
 */
class ExpireStorage extends Command
{
    protected $signature = 'events:expire-storage
        {--dry-run : List what would be sent, deleted and archived without changing anything}
        {--date= : Run as if today were this date (Y-m-d), e.g. to check a dry run ahead}';

    protected $description = 'Warn hosts before their storage period ends, delete expired media and archive old events';

    public function handle(StorageExpiry $expiry): int
    {
        $dry = (bool) $this->option('dry-run');
        $today = $this->option('date') ? CarbonImmutable::parse((string) $this->option('date')) : null;

        $report = $expiry->run($today, $dry);
        $prefix = $dry ? '[dry run] ' : '';

        foreach ($report['warned'] as $row) {
            $this->line("{$prefix}warning ({$row['days']} days): event {$row['event']}");
        }
        foreach ($report['purged'] as $row) {
            $this->line("{$prefix}purge: event {$row['event']} ({$row['files']} files, {$row['media']} media rows)");
        }
        foreach ($report['archived'] as $id) {
            $this->line("{$prefix}archive: event {$id}");
        }

        $this->info(sprintf('%s%d warning(s), %d purge(s), %d archive(s).', $prefix, count($report['warned']), count($report['purged']), count($report['archived'])));

        return self::SUCCESS;
    }
}
