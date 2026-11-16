<?php

declare(strict_types=1);

namespace App\Summaries;

use App\Models\Branch;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Services\Reports\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;

/**
 * Every 10 minutes (TRD §4.11, APP_FLOW §4.25 step 5): for today and yesterday of each branch,
 * logs an error when Σ payments ≠ Σ daily_payment_summaries, Σ folio_lines ≠
 * daily_summaries.net_sales_amount, or the summary is more than 10 minutes older than the newest
 * ledger row of the day. Nothing is corrected; the next rebuild replaces the day.
 */
final class CheckSummaries
{
    public const STALE_MINUTES = 10;

    public function __construct(
        private readonly SummaryDays $days,
        private readonly DayFigures $figures,
        private readonly BusinessDay $businessDays,
        private readonly DatabaseManager $db,
    ) {}

    /** @return list<string> the problems found; each one is also logged as an error */
    public function run(): array
    {
        $problems = [];

        foreach (Branch::query()->withoutGlobalScopes()->orderBy('id')->get(['id', 'name']) as $branch) {
            $today = $this->days->currentDate($branch->id);
            $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();

            foreach ([$today, $yesterday] as $date) {
                foreach ($this->day($branch->id, $date) as $problem) {
                    $problems[] = "{$branch->name} {$date}: {$problem}";
                }
            }
        }

        foreach ($problems as $problem) {
            Log::error('Summary check failed: '.$problem);
        }

        return $problems;
    }

    /** @return list<string> */
    public function day(int $branchId, string $date): array
    {
        $shiftIds = $this->businessDays->shifts($branchId, $date)->pluck('id')->all();

        if ($shiftIds === []) {
            return [];
        }

        $problems = [];

        $payments = Payment::query()->withoutGlobalScopes()->whereIn('shift_session_id', $shiftIds)->get();
        $expected = $this->figures->paymentRows($branchId, $date, $payments);
        $wantAmount = (int) array_sum(array_column($expected, 'amount'));
        $wantRefunds = (int) array_sum(array_column($expected, 'refund_amount'));
        $stored = $this->db->table('daily_payment_summaries')->where('branch_id', $branchId)->where('business_date', $date)
            ->selectRaw('COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(refund_amount), 0) AS refunds')->first();
        $haveAmount = (int) ($stored->amount ?? 0);
        $haveRefunds = (int) ($stored->refunds ?? 0);

        if ($haveAmount !== $wantAmount || $haveRefunds !== $wantRefunds) {
            $problems[] = "payments {$wantAmount} (refunds {$wantRefunds}) ≠ daily_payment_summaries {$haveAmount} (refunds {$haveRefunds})";
        }

        $lines = (int) FolioLine::query()->withoutGlobalScopes()->whereIn('shift_session_id', $shiftIds)->sum('amount');
        $net = $this->db->table('daily_summaries')->where('branch_id', $branchId)->where('business_date', $date)->value('net_sales_amount');

        if ($net === null) {
            $problems[] = 'no daily_summaries row';
        } elseif ((int) $net !== $lines) {
            $problems[] = "folio_lines {$lines} ≠ daily_summaries.net_sales_amount ".(int) $net;
        }

        $newest = max(
            (string) FolioLine::query()->withoutGlobalScopes()->whereIn('shift_session_id', $shiftIds)->max('created_at'),
            (string) Payment::query()->withoutGlobalScopes()->whereIn('shift_session_id', $shiftIds)->max('created_at'),
        );
        $rebuilt = $this->db->table('summary_days')->where('branch_id', $branchId)->where('business_date', $date)->value('rebuilt_at');

        if ($newest !== '' && ($rebuilt === null
            || CarbonImmutable::parse((string) $rebuilt, 'UTC')->addMinutes(self::STALE_MINUTES)->lessThan(CarbonImmutable::parse($newest, 'UTC')))) {
            $problems[] = 'summary is more than '.self::STALE_MINUTES.' minutes older than the newest ledger row';
        }

        return $problems;
    }
}
