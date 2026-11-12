<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Enums\ShiftStatus;
use App\Exceptions\DayNotClosable;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DayClosing;
use App\Models\ShiftSession;
use App\Models\User;
use App\Services\ReportService;
use App\Support\Clock;
use App\Support\DuplicateKey;
use App\Support\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Close a business day (screen 38b, DATABASE_SCHEMA §8.10b, TRD §4.10). One transaction:
 * lock the branch row; refuse (DayNotClosable, nothing written) while a shift of the date is
 * not closed, while an earlier date that has shifts is not closed, or when the reconcile check
 * finds a mismatch for that date: the nightly job's stored result, and the same check run now
 * (Gab, 12 Nov); take next_day_z_number; write day_closings with the first and last shift Z
 * numbers, the grand totals of the first and last shift, and short and over summed separately.
 * A second close of the same date returns the existing row.
 */
final class CloseDay
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $by, int $branchId, string $date): DayClosing
    {
        try {
            return $this->transaction->run(function () use ($by, $branchId, $date): DayClosing {
                $branch = Branch::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($branchId);

                $existing = DayClosing::query()->withoutGlobalScopes()->where('branch_id', $branchId)->whereDate('business_date', $date)->first();
                if ($existing !== null) {
                    return $existing;
                }

                $this->refuseUnlessClosable($branchId, $date);

                $shifts = ShiftSession::query()->withoutGlobalScopes()
                    ->where('branch_id', $branchId)->whereDate('business_date', $date)
                    ->orderBy('z_no')->get();
                /** @var ShiftSession $first */
                $first = $shifts->first();
                /** @var ShiftSession $last */
                $last = $shifts->last();
                $before = $first->grand_total_before?->centavos() ?? 0;
                $after = $last->grand_total_after?->centavos() ?? 0;
                $variances = $shifts->map(fn (ShiftSession $s): int => $s->variance_amount?->centavos() ?? 0);

                $no = $branch->next_day_z_number;
                $branch->update(['next_day_z_number' => $no + 1]);

                $closing = DayClosing::query()->create([
                    'branch_id' => $branchId,
                    'business_date' => $date,
                    'day_z_no' => $no,
                    'net_sales_amount' => $after - $before,
                    'short_amount' => (int) $variances->filter(fn (int $v): bool => $v < 0)->sum(fn (int $v): int => -$v),
                    'over_amount' => (int) $variances->filter(fn (int $v): bool => $v > 0)->sum(),
                    'grand_total_before' => $before,
                    'grand_total_after' => $after,
                    'shift_z_from' => (int) $first->z_no,
                    'shift_z_to' => (int) $last->z_no,
                    'closed_by' => $by->id,
                    'created_at' => $this->clock->now(),
                ]);

                AuditLog::query()->create([
                    'branch_id' => $branchId,
                    'actor_id' => $by->id,
                    'action' => 'day.closed',
                    'subject_type' => 'day_closing',
                    'subject_id' => $closing->id,
                    'after_json' => ['date' => $date, 'day_z_no' => $no, 'net_sales' => $after - $before],
                    'created_at' => $this->clock->now(),
                ]);

                return $closing;
            });
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'day_closings_date_unique')) {
                return DayClosing::query()->withoutGlobalScopes()->where('branch_id', $branchId)->whereDate('business_date', $date)->firstOrFail();
            }

            throw $e;
        }
    }

    /**
     * Why the date cannot be closed, or null when it can. The page shows the same sentence the
     * action refuses with.
     */
    public function reason(int $branchId, string $date): ?string
    {
        $shifts = ShiftSession::query()->withoutGlobalScopes()->where('branch_id', $branchId)->whereDate('business_date', $date)->get();

        if ($shifts->isEmpty()) {
            return 'There are no shifts on this date.';
        }

        if ($shifts->contains(fn (ShiftSession $s): bool => $s->status !== ShiftStatus::Closed)) {
            return 'A shift of this date is still open. Close it first.';
        }

        $earlier = ShiftSession::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)->whereDate('business_date', '<', $date)
            ->orderByDesc('business_date')->value('business_date');

        if ($earlier !== null) {
            $earlierDate = substr((string) $earlier, 0, 10);
            $closed = DayClosing::query()->withoutGlobalScopes()->where('branch_id', $branchId)->whereDate('business_date', $earlierDate)->exists();

            if (! $closed) {
                return 'Close '.CarbonImmutable::parse($earlierDate)->format('D j M').' first. Days are closed in date order.';
            }
        }

        if ($this->reports->storedMismatches($branchId, $date, $date) !== [] || $this->reports->reconcileDay($branchId, $date) !== []) {
            return 'The check found figures that differ from the ledger for this date. The day cannot be closed until they agree.';
        }

        return null;
    }

    private function refuseUnlessClosable(int $branchId, string $date): void
    {
        $reason = $this->reason($branchId, $date);

        if ($reason !== null) {
            throw DayNotClosable::because($reason);
        }
    }
}
