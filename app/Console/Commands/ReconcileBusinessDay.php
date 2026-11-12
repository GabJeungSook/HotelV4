<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BranchStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Services\ReportService;
use App\Support\Clock;
use Illuminate\Console\Command;

/**
 * Daily at 09:00 Asia/Manila (APP_FLOW §4.21, SYSTEM_DESIGN §5.4): for each branch, recompute
 * the previous business date's closed shifts and the day from the ledger through ReportService
 * and compare them with the frozen totals. A difference writes one audit_logs row
 * `reconcile.mismatch` with the stored and recomputed figures (screens 36, 38), and CloseDay
 * then refuses that date. Equal: nothing is written. Nothing is ever corrected.
 */
final class ReconcileBusinessDay extends Command
{
    protected $signature = 'homi:reconcile-business-day {--date= : The business date to check (default: yesterday)}';

    protected $description = 'Recompute yesterday\'s shifts from the ledger and flag any figure that differs from the frozen totals';

    public function handle(ReportService $reports, Clock $clock): int
    {
        $option = $this->option('date');
        $date = is_string($option) && $option !== '' ? $option : $clock->local()->subDay()->toDateString();
        $flagged = 0;

        foreach (Branch::query()->withoutGlobalScopes()->where('status', BranchStatus::Active)->get() as $branch) {
            $differences = $reports->reconcileDay($branch->id, $date);

            if ($differences === []) {
                continue;
            }

            $flagged++;
            AuditLog::query()->create([
                'branch_id' => $branch->id,
                'actor_id' => null,
                'action' => 'reconcile.mismatch',
                'subject_type' => 'business_date',
                'subject_id' => (int) str_replace('-', '', $date),
                'after_json' => ['date' => $date, 'differences' => $differences],
                'created_at' => $clock->now(),
            ]);
            $this->warn("{$branch->name}: ".count($differences)." figure(s) differ for {$date}.");
        }

        $this->info($flagged === 0 ? "Check passed for {$date}." : "{$flagged} branch(es) flagged for {$date}.");

        return $flagged === 0 ? self::SUCCESS : self::FAILURE;
    }
}
