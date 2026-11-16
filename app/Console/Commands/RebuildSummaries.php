<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Summaries\RebuildSummaries as Rebuild;
use Illuminate\Console\Command;

/** Every minute (TRD §6): rebuild the dirty branch-days and every branch's today (APP_FLOW §4.25). */
final class RebuildSummaries extends Command
{
    protected $signature = 'homi:rebuild-summaries';

    protected $description = 'Recompute the dashboard summary tables for dirty branch-days and today';

    public function handle(Rebuild $rebuild): int
    {
        $days = $rebuild->run();
        $this->info('Rebuilt '.count($days).' branch-day(s).');

        return self::SUCCESS;
    }
}
