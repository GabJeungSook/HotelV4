<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Actions\Auth\ApproveByPin;
use App\Enums\ApprovalAction;
use App\Models\Approval;
use App\Models\CashCount;
use App\Models\CashCountDraft;
use App\Models\ShiftHandover;
use App\Models\ShiftSession;
use App\Models\User;
use App\Services\CashMath;
use App\Services\CurrentShift;
use App\Support\CashPieces;
use App\Support\Clock;
use App\Support\Money;
use App\Support\Transaction;

/**
 * Force close a shift left open (APP_FLOW §4.16 "Force close", TRD §4.9; its screen, 49, comes
 * with the admin pages). A supervisor or admin counts the drawer and another approver gives a
 * PIN (force_close_shift; approve_variance as well when the count is off). The shift is frozen
 * like any close, with its Z number and grand total, is_force_closed = 1, and a handover with no
 * receiving shift. Works on an `open` or a `closing` shift; a count already confirmed is kept.
 */
final class ForceCloseShift
{
    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly CloseShift $close,
        private readonly CashMath $cash,
        private readonly ApproveByPin $approveByPin,
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    /** @param  array<array-key, mixed>  $pieces */
    public function handle(User $by, int $branchId, array $pieces, User $approver, string $pin, string $deviceKey): ShiftSession
    {
        return $this->transaction->run(function () use ($by, $branchId, $pieces, $approver, $pin, $deviceKey): ShiftSession {
            $shift = $this->currentShift->for($branchId);
            $label = "{$shift->period_name} shift · {$shift->business_date->toDateString()}";

            $approval = $this->approveByPin->handle(
                $approver, $pin, $deviceKey, $by, $branchId,
                ApprovalAction::ForceCloseShift, 'shift_session', $shift->id,
                'Shift left open', "Force close · {$label}",
            );

            $count = CashCount::query()->where('shift_session_id', $shift->id)->first();

            if ($count === null) {
                $normalized = CashPieces::normalize($pieces);
                $count = CashCount::query()->create([
                    'shift_session_id' => $shift->id,
                    'denominations' => $normalized,
                    'total_cash' => CashPieces::total($normalized),
                    'counted_by' => $by->id,
                    'created_at' => $this->clock->now(),
                ]);
                CashCountDraft::query()->where('shift_session_id', $shift->id)->delete();
            }

            $expected = $this->close->expected($shift);
            $counted = $count->total_cash ?? Money::zero();
            $variance = $this->cash->variance($counted, $expected);
            // Marked force-closed with the variance: a force close needs no variance note (shift_sessions_variance_chk).
            $shift->update(['expected_cash' => $expected, 'counted_cash' => $counted, 'variance_amount' => $variance, 'is_force_closed' => true]);

            if (! $variance->isZero()) {
                Approval::query()->create([
                    'branch_id' => $branchId,
                    'action' => ApprovalAction::ApproveVariance,
                    'subject_type' => 'shift_session',
                    'subject_id' => $shift->id,
                    'requested_by' => $by->id,
                    'approved_by' => $approval->approved_by,
                    'reason' => 'Force close',
                    'summary' => mb_substr(($variance->isNegative() ? 'Short ' : 'Over ').$variance->abs()->format()." · {$label}", 0, 255),
                    'created_at' => $this->clock->now(),
                ]);
            }

            $this->close->freeze($shift, $by, null, forceClosed: true);
            ShiftHandover::query()->create(['from_shift_id' => $shift->id, 'to_shift_id' => null, 'created_at' => $this->clock->now()]);

            return $shift;
        });
    }
}
