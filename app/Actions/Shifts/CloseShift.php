<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Enums\ReasonKind;
use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Exceptions\ActionRefused;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CashCount;
use App\Models\CashCountDraft;
use App\Models\CashMovement;
use App\Models\Payment;
use App\Models\Reason;
use App\Models\ShiftForwardedStay;
use App\Models\ShiftSession;
use App\Models\User;
use App\Queries\StayQuery;
use App\Services\CashMath;
use App\Services\CurrentShift;
use App\Services\ReportService;
use App\Support\CashPieces;
use App\Support\Clock;
use App\Support\Money;
use App\Support\ReasonText;
use App\Support\Transaction;

/**
 * Close the shift, steps 1 to 3 and the freeze (screens 22–24, APP_FLOW §4.16,
 * DATABASE_SCHEMA §8.10, TRD §4.10 and §4.12). Each step is its own transaction with the shift
 * locked first; the step the wizard shows is read back from the database.
 *
 * - begin(): open → closing ("Next: count cash"); records who started it, so the other member
 *   is told "Jessa Mae is closing the shift" and their next money write gets ShiftClosing.
 * - notYet(): closing → open, possible only while no cash_counts row exists.
 * - count(): the blind count. The server adds up the pieces; one cash_counts row per shift,
 *   never changed; the draft is deleted with it.
 * - result(): expected cash (CashMath), variance = counted − expected, and the explanation a
 *   variance other than ₱0.00 needs (a `variance` reason and its note). Still closing.
 * - freeze(): called inside HandOverShift's transaction: shift_forwarded_stays, the branch's
 *   next Z number (branch row locked last), the chained grand total, frozen `totals`, closed.
 */
final class CloseShift
{
    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly CashMath $cash,
        private readonly ReportService $reports,
        private readonly StayQuery $stays,
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    public function begin(User $by, int $branchId): ShiftSession
    {
        return $this->transaction->run(function () use ($by, $branchId): ShiftSession {
            $shift = $this->currentShift->for($branchId);

            if ($shift->status === ShiftStatus::Closing) {
                return $shift;
            }

            $shift->update(['status' => ShiftStatus::Closing]);
            $this->audit($by, $shift, CurrentShift::CLOSING_STARTED);

            return $shift;
        });
    }

    public function notYet(User $by, int $branchId): ShiftSession
    {
        return $this->transaction->run(function () use ($by, $branchId): ShiftSession {
            $shift = $this->currentShift->for($branchId);

            if ($shift->status !== ShiftStatus::Closing) {
                return $shift;
            }

            if (CashCount::query()->where('shift_session_id', $shift->id)->exists()) {
                throw ActionRefused::because('The cash is already counted, so the close cannot be stopped now. Carry on from where it is.');
            }

            CashCountDraft::query()->where('shift_session_id', $shift->id)->delete();
            $shift->update(['status' => ShiftStatus::Open]);
            $this->audit($by, $shift, 'shift.closing_stopped');

            return $shift;
        });
    }

    /**
     * Confirm the count (23). A second confirm returns the saved count and changes nothing.
     *
     * @param  array<array-key, mixed>  $pieces  pieces per bill and "coins"; any total is ignored
     */
    public function count(User $by, int $branchId, array $pieces, ?User $witness = null): CashCount
    {
        return $this->transaction->run(function () use ($by, $branchId, $pieces, $witness): CashCount {
            $shift = $this->closing($branchId);
            $saved = CashCount::query()->where('shift_session_id', $shift->id)->first();

            if ($saved !== null) {
                return $saved;
            }

            if ($witness !== null && ($witness->id === $by->id || $witness->branch_id !== $branchId || ! $witness->is_active
                || ! in_array($witness->role, [Role::Frontdesk, Role::Supervisor, Role::Admin], true))) {
                throw ActionRefused::because('Pick another person of this branch as witness.');
            }

            $normalized = CashPieces::normalize($pieces);

            $count = CashCount::query()->create([
                'shift_session_id' => $shift->id,
                'denominations' => $normalized,
                'total_cash' => CashPieces::total($normalized),
                'counted_by' => $by->id,
                'witness_id' => $witness?->id,
                'created_at' => $this->clock->now(),
            ]);

            CashCountDraft::query()->where('shift_session_id', $shift->id)->delete();

            return $count;
        });
    }

    /** Step 3 (24): expected cash, the variance and its explanation, written on the shift. */
    public function result(User $by, int $branchId, ?Reason $reason = null, ?string $note = null): ShiftSession
    {
        return $this->transaction->run(function () use ($branchId, $reason, $note): ShiftSession {
            $shift = $this->closing($branchId);
            $count = CashCount::query()->where('shift_session_id', $shift->id)->first()
                ?? throw ActionRefused::because('Count the cash first.');

            $expected = $this->expected($shift);
            $counted = $count->total_cash ?? Money::zero();
            $variance = $this->cash->variance($counted, $expected);
            $text = null;
            $reasonId = null;

            if (! $variance->isZero()) {
                if ($reason === null || $reason->branch_id !== $branchId) {
                    throw ActionRefused::because('Say what happened: pick a reason for the difference.');
                }

                $text = ReasonText::for($reason, ReasonKind::Variance, $note);
                $reasonId = $reason->id;
            }

            $shift->update([
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'variance_amount' => $variance,
                'variance_reason_id' => $reasonId,
                'variance_note' => $text === null ? null : mb_substr($text, 0, 255),
            ]);

            return $shift;
        });
    }

    /** The cash expected in the drawer of this shift now (TRD §4.5). */
    public function expected(ShiftSession $shift): Money
    {
        return $this->cash->expectedFromRows(
            $shift->opening_cash ?? Money::zero(),
            Payment::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->get(),
            CashMovement::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->get(),
        );
    }

    /**
     * Freeze the shift (DATABASE_SCHEMA §8.10 step 4/5). Must run inside the caller's
     * transaction with the shift already locked; locks the branch row last for the Z number.
     */
    public function freeze(ShiftSession $shift, User $closedBy, ?string $handoverNote = null, bool $forceClosed = false): ShiftSession
    {
        $now = $this->clock->now();

        foreach ($this->stays->inHouse($shift->branch_id) as $row) {
            ShiftForwardedStay::query()->create([
                'shift_session_id' => $shift->id,
                'stay_id' => $row['stay_id'],
                'room_id' => $row['room_id'],
                'guest_name' => mb_substr($row['guest_name'], 0, 80),
                'expected_checkout_at' => $row['expected_checkout_at'],
                'unpaid_amount' => $row['unpaid'],
                'key_deposit_held' => $row['key_deposit_held'],
                'guest_deposit_held' => $row['guest_deposit_held'],
                'created_at' => $now,
            ]);
        }

        $shift->is_force_closed = $forceClosed;
        $forwardedOut = ShiftForwardedStay::query()->where('shift_session_id', $shift->id)->orderBy('id')->get();
        $totals = $this->reports->computeShiftTotals($shift, $this->reports->forwardedIn($shift), $forwardedOut, $now);

        $branch = Branch::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($shift->branch_id);
        $zNo = $branch->next_z_number;
        $branch->update(['next_z_number' => $zNo + 1]);

        $before = ShiftSession::query()->withoutGlobalScopes()
            ->where('branch_id', $shift->branch_id)
            ->where('status', ShiftStatus::Closed)
            ->whereNotNull('z_no')
            ->orderByDesc('z_no')
            ->first()?->grand_total_after;
        $before ??= Money::zero();
        $sales = is_array($totals['sales'] ?? null) && is_int($totals['sales']['total'] ?? null) ? $totals['sales']['total'] : 0;

        $note = $handoverNote === null ? null : trim($handoverNote);

        $shift->update([
            'handover_note' => $note === null || $note === '' ? null : mb_substr($note, 0, 500),
            'totals' => $totals,
            'status' => ShiftStatus::Closed,
            'closed_at' => $now,
            'closed_by' => $closedBy->id,
            'is_force_closed' => $forceClosed,
            'z_no' => $zNo,
            'grand_total_before' => $before,
            'grand_total_after' => $before->plus(Money::ofCentavos($sales)),
        ]);

        CashCountDraft::query()->where('shift_session_id', $shift->id)->delete();
        $this->audit($closedBy, $shift, 'shift.closed', ['z_no' => $zNo]);

        return $shift;
    }

    private function closing(int $branchId): ShiftSession
    {
        $shift = $this->currentShift->for($branchId);

        if ($shift->status !== ShiftStatus::Closing) {
            throw ActionRefused::because('Start the close first (Close shift, then Next: count cash).');
        }

        return $shift;
    }

    /** @param  array<string, mixed>|null  $after */
    private function audit(User $by, ShiftSession $shift, string $action, ?array $after = null): void
    {
        AuditLog::query()->create([
            'branch_id' => $shift->branch_id,
            'actor_id' => $by->id,
            'action' => $action,
            'subject_type' => 'shift_session',
            'subject_id' => $shift->id,
            'after_json' => $after ?? ['status' => $shift->status->value],
            'created_at' => $this->clock->now(),
        ]);
    }
}
