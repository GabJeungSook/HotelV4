<?php

declare(strict_types=1);

namespace App\Summaries;

use App\Enums\CleaningStatus;
use App\Enums\ShiftStatus;
use App\Models\CleaningTask;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Which branch-days need their summaries rebuilt (DATABASE_SCHEMA §4.16, APP_FLOW §4.25).
 *
 * A ledger row (folio line, payment) marks its shift's business date dirty when it is created;
 * so do a check-in, a check-out, a finished cleaning and a closed or force-closed shift, so
 * counts that are not ledger rows are refreshed too (TRD §4.11). The marks are written after the
 * action's transaction commits, so an action never waits on the summary_days row and a rolled
 * back action marks nothing. "Dirty" is dirty_at >= rebuilt_at; today is always treated as dirty.
 */
final class SummaryDays
{
    /** @var array<string, array{0: int, 1: string}> pending marks of this transaction, by "branch|date" */
    private array $pending = [];

    /** @var array<int, string> shift id => business date, so a busy action asks once */
    private array $shiftDates = [];

    public function __construct(
        private readonly Clock $clock,
        private readonly DatabaseManager $db,
    ) {}

    /** Hook the ledger and state models (called from AppServiceProvider::boot; no queries here). */
    public static function listen(): void
    {
        FolioLine::created(fn (FolioLine $line) => self::instance()->markShift($line->branch_id, $line->shift_session_id));
        Payment::created(fn (Payment $payment) => self::instance()->markShift($payment->branch_id, $payment->shift_session_id));

        Stay::saved(function (Stay $stay): void {
            if ($stay->wasChanged('checked_in_at') || ($stay->wasRecentlyCreated && $stay->checked_in_at !== null)) {
                self::instance()->markShift($stay->branch_id, $stay->checkin_shift_id);
            }
            if ($stay->wasChanged('checked_out_at') || $stay->wasChanged('state')) {
                self::instance()->markShift($stay->branch_id, $stay->checkout_shift_id ?? $stay->checkin_shift_id);
            }
        });

        CleaningTask::saved(function (CleaningTask $task): void {
            if ($task->wasChanged('status') && $task->status === CleaningStatus::Done) {
                self::instance()->markCurrent($task->branch_id);
            }
        });

        ShiftSession::saved(function (ShiftSession $shift): void {
            if ($shift->wasChanged('status') && $shift->status === ShiftStatus::Closed) {
                self::instance()->mark($shift->branch_id, $shift->business_date->toDateString());
            }
        });
    }

    public static function instance(): self
    {
        return app(self::class);
    }

    /** Mark the business date of a shift dirty. */
    public function markShift(int $branchId, ?int $shiftId): void
    {
        if ($shiftId === null) {
            $this->markCurrent($branchId);

            return;
        }

        $date = $this->shiftDates[$shiftId] ??= (string) ShiftSession::query()->withoutGlobalScopes()->whereKey($shiftId)->value('business_date');

        if ($date !== '') {
            $this->mark($branchId, substr($date, 0, 10));
        }
    }

    /** Mark the branch's current business date dirty. */
    public function markCurrent(int $branchId): void
    {
        $this->mark($branchId, $this->currentDate($branchId));
    }

    public function mark(int $branchId, string $date): void
    {
        $this->pending["{$branchId}|{$date}"] = [$branchId, $date];

        // Runs at once outside a transaction, after the outermost commit inside one. Registered on
        // every mark because a rolled-back transaction drops its callbacks; later ones are no-ops.
        $this->db->connection()->afterCommit(fn () => $this->flush());
    }

    /** Write the pending marks now. */
    public function flush(): void
    {
        $rows = [];
        $at = $this->clock->now()->format('Y-m-d H:i:s');

        foreach ($this->pending as [$branchId, $date]) {
            $rows[] = ['branch_id' => $branchId, 'business_date' => $date, 'dirty_at' => $at, 'rebuilt_at' => null];
        }

        $this->pending = [];
        $this->shiftDates = [];

        if ($rows !== []) {
            $this->db->table('summary_days')->upsert($rows, ['branch_id', 'business_date'], ['dirty_at']);
        }
    }

    /**
     * The business date a branch is working on now: its open shift's, otherwise the local date
     * (TRD §4.6). Dashboards call this "today".
     */
    public function currentDate(int $branchId): string
    {
        $open = ShiftSession::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereIn('status', [ShiftStatus::Open, ShiftStatus::Closing])
            ->value('business_date');

        return $open === null ? $this->clock->localDate() : substr((string) $open, 0, 10);
    }

    /**
     * Every branch-day to rebuild now: dirty ones and each branch's today.
     *
     * @param  list<int>  $branchIds
     * @return list<array{0: int, 1: string}>
     */
    public function due(array $branchIds): array
    {
        $days = [];

        foreach ($this->db->table('summary_days')
            ->whereNotNull('dirty_at')
            ->where(fn ($q) => $q->whereNull('rebuilt_at')->orWhereColumn('dirty_at', '>=', 'rebuilt_at'))
            ->orderBy('business_date')
            ->get(['branch_id', 'business_date']) as $row) {
            $days[$row->branch_id.'|'.substr((string) $row->business_date, 0, 10)] = [(int) $row->branch_id, substr((string) $row->business_date, 0, 10)];
        }

        foreach ($branchIds as $branchId) {
            $date = $this->currentDate($branchId);
            $days["{$branchId}|{$date}"] = [$branchId, $date];
        }

        return array_values($days);
    }

    public function rebuilt(int $branchId, string $date, CarbonImmutable $startedAt): void
    {
        $this->db->table('summary_days')->upsert(
            [['branch_id' => $branchId, 'business_date' => $date, 'dirty_at' => null, 'rebuilt_at' => $startedAt->format('Y-m-d H:i:s')]],
            ['branch_id', 'business_date'],
            ['rebuilt_at'],
        );
    }
}
