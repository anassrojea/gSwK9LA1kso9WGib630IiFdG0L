<?php

namespace App\Console\Commands;

use App\Services\Journey\Anniversaries;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily (routes/console.php): the yearly anniversary email to hosts
 * (App\Services\Journey\Anniversaries). Safe to run more than once a day.
 */
class SendAnniversaries extends Command
{
    protected $signature = 'journey:anniversaries
        {--dry-run : List the emails that would go out without sending}
        {--date= : Run as if today were this date (Y-m-d)}';

    protected $description = 'Send the yearly anniversary email for events that took place a year (or more) ago today';

    public function handle(Anniversaries $anniversaries): int
    {
        $dry = (bool) $this->option('dry-run');
        $today = $this->option('date') ? CarbonImmutable::parse((string) $this->option('date')) : null;

        $sent = $anniversaries->run($today, $dry);
        foreach ($sent as $row) {
            $this->line(($dry ? '[dry run] ' : '')."anniversary {$row['year']}: event {$row['event']}");
        }
        $this->info(($dry ? '[dry run] ' : '').count($sent).' anniversary email(s).');

        return self::SUCCESS;
    }
}
