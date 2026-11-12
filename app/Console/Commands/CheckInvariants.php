<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Support\Clock;
use App\Support\Invariants;
use Illuminate\Console\Command;

/**
 * Daily at 09:05 Asia/Manila (TRD §8, SYSTEM_DESIGN §5.4): evaluate invariants 1–8. Each broken
 * invariant writes one audit_logs row `invariant.failed` with its sentence; the attention list
 * (49) shows them. Nothing is ever corrected.
 */
final class CheckInvariants extends Command
{
    protected $signature = 'homi:check-invariants';

    protected $description = 'Check the data invariants of TRD §8 and record every failure';

    public function handle(Clock $clock): int
    {
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
