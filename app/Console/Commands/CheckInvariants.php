<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Support\Clock;
use App\Support\Invariants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily at 09:05 Asia/Manila (TRD §8, SYSTEM_DESIGN §5.4): evaluate invariants 1–8. Each broken
 * invariant writes one audit_logs row `invariant.failed` with its sentence; the attention list
 * (49) shows them. Nothing is ever corrected.
 *
 * `--database=` checks a copy instead, such as a restored backup (scripts/restore-test.sh). It
 * switches the default connection for this run only, so it works with a cached config too.
 */
final class CheckInvariants extends Command
{
    protected $signature = 'homi:check-invariants {--database= : Check this database (a restored copy) instead of the live one}';

    protected $description = 'Check the data invariants of TRD §8 and record every failure';

    public function handle(Clock $clock): int
    {
        $copy = $this->option('database');

        if (is_string($copy) && $copy !== '') {
            $connection = (string) config('database.default');
            config(["database.connections.{$connection}.database" => $copy]);
            DB::purge($connection);
            $this->line("Checking the copy in database {$copy}.");
        }

        $problems = Invariants::check();

        foreach ($problems as $problem) {
            AuditLog::query()->create([
                'branch_id' => null,
                'actor_id' => null,
                'action' => 'invariant.failed',
                'subject_type' => 'invariant',
                'subject_id' => (int) $problem[0],
                'after_json' => ['message' => $problem],
                'created_at' => $clock->now(),
            ]);
            $this->warn($problem);
        }

        $this->info($problems === [] ? 'All invariants hold.' : count($problems).' invariant failure(s) recorded.');

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
