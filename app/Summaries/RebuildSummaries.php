<?php

declare(strict_types=1);

namespace App\Summaries;

use App\Models\Branch;
use App\Support\Clock;
use App\Support\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Every minute (TRD §4.11, APP_FLOW §4.25): for each dirty branch-day and each branch's today,
 * recompute the day from the ledgers and replace that day's summary rows in one transaction.
 * rebuilt_at is the start time of the run, so a ledger row written while the run is going keeps
 * the day dirty (dirty_at >= rebuilt_at) and the next run picks it up.
 */
final class RebuildSummaries
{
    public const TABLES = ['daily_summaries', 'hourly_sales_summaries', 'daily_payment_summaries', 'daily_report_lines'];

    public function __construct(
        private readonly SummaryDays $days,
        private readonly DayFigures $figures,
        private readonly Clock $clock,
        private readonly Transaction $transaction,
        private readonly DatabaseManager $db,
    ) {}

    /** @return list<array{0: int, 1: string}> the branch-days rebuilt */
    public function run(): array
    {
        $startedAt = $this->clock->now();
        $this->days->flush();
        $branchIds = Branch::query()->withoutGlobalScopes()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $due = $this->days->due($branchIds);

        foreach ($due as [$branchId, $date]) {
            $this->day($branchId, $date, $startedAt);
        }

        return $due;
    }

    /** Rebuild one branch-day. */
    public function day(int $branchId, string $date, ?CarbonImmutable $startedAt = null): void
    {
        $startedAt ??= $this->clock->now();

        $this->transaction->run(function () use ($branchId, $date, $startedAt): void {
            $rows = $this->figures->compute($branchId, $date);

            foreach (self::TABLES as $table) {
                $this->db->table($table)->where('branch_id', $branchId)->where('business_date', $date)->delete();
            }

            $this->db->table('daily_summaries')->insert($rows['daily']);

            foreach (['hourly_sales_summaries' => 'hourly', 'daily_payment_summaries' => 'payments', 'daily_report_lines' => 'lines'] as $table => $key) {
                if ($rows[$key] !== []) {
                    $this->db->table($table)->insert($rows[$key]);
                }
            }

            $this->days->rebuilt($branchId, $date, $startedAt);
        });
    }
}
