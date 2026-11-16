<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Summaries\CheckSummaries as Check;
use Illuminate\Console\Command;

/** Every 10 minutes (TRD §6): compare today's and yesterday's summaries with the ledgers; logs, never fixes. */
final class CheckSummaries extends Command
{
    protected $signature = 'homi:check-summaries';

    protected $description = 'Check the summary tables against the ledgers for today and yesterday';

    public function handle(Check $check): int
    {
        $problems = $check->run();

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        $this->info($problems === [] ? 'Summaries agree with the ledgers.' : count($problems).' problem(s) logged.');

        return self::SUCCESS;
    }
}
