<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Actions\Auth\ApproveByPin;
use App\Actions\Auth\VerifyPin;
use App\Enums\ApprovalAction;
use App\Enums\PinContext;
use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Exceptions\ActionRefused;
use App\Models\Approval;
use App\Models\CashCount;
use App\Models\ShiftDefinition;
use App\Models\ShiftHandover;
use App\Models\ShiftMember;
use App\Models\ShiftSession;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\ShiftCalendar;
use App\Support\Clock;
use App\Support\Money;
use App\Support\Transaction;

/**
 * Close step 4, the handover (screen 25, APP_FLOW §4.16 steps 8–11, DATABASE_SCHEMA §8.10).
 *
 * toReceiver(): in ONE transaction, the incoming front desk (never the person who counted)
 * accepts with their PIN; the shift is frozen (CloseShift::freeze: forwarded stays, Z number,
 * grand total, totals, closed) and the next shift opens with the cash they counted, with them
 * as its member, and the shift_handovers row links the two. There is never a moment with no
 * open shift and never two. A count different from the closing count needs a note ("Amount
 * is different — report it"); the difference stays on the handover for the supervisor. By
 * accepting, the receiver also accepts the variance; no approval row is written.
 *
 * withoutReceiver(): nobody is there to receive. A supervisor or admin other than the counter
 * approves (close_without_handover), and also signs off a variance other than ₱0.00
 * (approve_variance). The shift is frozen the same way; the handover has no receiving shift
 * and the next OpenShift links itself to it.
 */
final class HandOverShift
{
    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly CloseShift $close,
        private readonly VerifyPin $verifyPin,
        private readonly ApproveByPin $approveByPin,
        private readonly ShiftCalendar $calendar,
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    /** @return ShiftSession the new, open shift */
    public function toReceiver(
        User $by,
        int $branchId,
        User $receiver,
        string $receiverPin,
        Money $receivedCash,
        string $deviceKey,
        ?string $handoverNote = null,
        ?string $differenceNote = null,
    ): ShiftSession {
        return $this->transaction->run(function () use ($by, $branchId, $receiver, $receiverPin, $receivedCash, $deviceKey, $handoverNote, $differenceNote): ShiftSession {
            [$shift, $count] = $this->ready($branchId);

            if ($receiver->id === $count->counted_by) {
                throw ActionRefused::because('The person who counted the cash cannot receive the shift. Another front desk must count it again and accept.');
            }

            if (! $receiver->is_active || $receiver->branch_id !== $branchId || ! in_array($receiver->role, [Role::Frontdesk, Role::Supervisor], true)) {
                throw ActionRefused::because('Pick the incoming front desk of this branch.');
            }

            if ($receivedCash->isNegative()) {
                throw ActionRefused::because('The drawer count cannot be below zero.');
            }

            $counted = $count->total_cash ?? Money::zero();
            $differenceNote = $differenceNote === null ? null : trim($differenceNote);

            if (! $receivedCash->equals($counted) && ($differenceNote === null || $differenceNote === '')) {
                throw ActionRefused::noteRequired('a drawer count different from the closing count');
            }

            $this->verifyPin->handle($receiver, $receiverPin, $deviceKey, PinContext::Handover);

            $this->close->freeze($shift, $by, $handoverNote);

            $definitions = ShiftDefinition::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('is_active', true)->get()->all();
            [$definition, $date] = $this->calendar->following($definitions, $shift->shift_definition_id, $shift->business_date->toDateString());
            $now = $this->clock->now();
            $note = $differenceNote === null || $differenceNote === '' ? null : mb_substr($differenceNote, 0, 255);

            $next = ShiftSession::query()->create([
                'branch_id' => $branchId,
                'shift_definition_id' => $definition->id,
                'period_name' => $definition->name,
                'business_date' => $date,
                'status' => ShiftStatus::Open,
                'opened_at' => $now,
                'opened_by' => $receiver->id,
                'opening_cash' => $receivedCash,
                'opening_note' => $note,
            ]);

            ShiftMember::query()->create(['shift_session_id' => $next->id, 'user_id' => $receiver->id, 'joined_at' => $now]);

            ShiftHandover::query()->create([
                'from_shift_id' => $shift->id,
                'to_shift_id' => $next->id,
                'received_by' => $receiver->id,
                'received_cash' => $receivedCash,
                'difference_amount' => $receivedCash->minus($counted),
                'note' => $note,
                'created_at' => $now,
            ]);

            return $next;
        });
    }

    /** @return ShiftSession the closed shift */
    public function withoutReceiver(User $by, int $branchId, User $approver, string $pin, string $deviceKey, ?string $handoverNote = null): ShiftSession
    {
        return $this->transaction->run(function () use ($by, $branchId, $approver, $pin, $deviceKey, $handoverNote): ShiftSession {
            [$shift, $count] = $this->ready($branchId);

            if ($approver->id === $count->counted_by) {
                throw ActionRefused::because('The person who counted the cash cannot approve this close. Ask another supervisor.');
            }

            $label = "{$shift->period_name} shift · {$shift->business_date->toDateString()}";
            $approval = $this->approveByPin->handle(
                $approver, $pin, $deviceKey, $by, $branchId,
                ApprovalAction::CloseWithoutHandover, 'shift_session', $shift->id,
                'Nobody to receive the shift', "Close without handover · {$label}",
            );

            $variance = $shift->variance_amount ?? Money::zero();

            if (! $variance->isZero()) {
                Approval::query()->create([
                    'branch_id' => $branchId,
                    'action' => ApprovalAction::ApproveVariance,
                    'subject_type' => 'shift_session',
                    'subject_id' => $shift->id,
                    'requested_by' => $by->id,
                    'approved_by' => $approval->approved_by,
                    'reason_id' => $shift->variance_reason_id,
                    'reason' => mb_substr($shift->variance_note ?? 'Variance', 0, 255),
                    'summary' => mb_substr(($variance->isNegative() ? 'Short ' : 'Over ').$variance->abs()->format()." · {$label}", 0, 255),
                    'created_at' => $this->clock->now(),
                ]);
            }

            $this->close->freeze($shift, $by, $handoverNote);

            ShiftHandover::query()->create([
                'from_shift_id' => $shift->id,
                'to_shift_id' => null,
                'created_at' => $this->clock->now(),
            ]);

            return $shift;
        });
    }

    /**
     * The closing shift, locked, with its count and its step 3 result saved.
     *
     * @return array{0: ShiftSession, 1: CashCount}
     */
    private function ready(int $branchId): array
    {
        $shift = $this->currentShift->for($branchId);

        if ($shift->status !== ShiftStatus::Closing) {
            throw ActionRefused::because('Start the close first (Close shift, then Next: count cash).');
        }

        $count = CashCount::query()->where('shift_session_id', $shift->id)->first()
            ?? throw ActionRefused::because('Count the cash first.');

        if ($shift->expected_cash === null) {
            throw ActionRefused::because('Finish step 3 first: check the result and press Next: hand over.');
        }

        return [$shift, $count];
    }
}
