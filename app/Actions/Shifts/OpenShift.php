<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Actions\Auth\ApproveByPin;
use App\Enums\ApprovalAction;
use App\Enums\ShiftStatus;
use App\Exceptions\ActionRefused;
use App\Exceptions\ShiftAlreadyOpen;
use App\Models\ShiftDefinition;
use App\Models\ShiftHandover;
use App\Models\ShiftMember;
use App\Models\ShiftSession;
use App\Models\User;
use App\Queries\ShiftQuery;
use App\Services\CurrentShift;
use App\Support\Clock;
use App\Support\DuplicateKey;
use App\Support\Money;
use App\Support\Transaction;
use Illuminate\Database\QueryException;

/**
 * Open the branch's one shift (screen 2, APP_FLOW §4.2, TRD §4.6).
 *
 * Proposes the period after the last closed shift (or, before any shift was ever closed, the
 * period running now). Another period needs a supervisor PIN (change_shift_period) once a
 * shift has been closed. A drawer count different from the last counted cash needs a note.
 * Links a handover left without a receiver. A second open shift → ShiftAlreadyOpen.
 */
final class OpenShift
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CurrentShift $currentShift,
        private readonly ShiftQuery $shifts,
        private readonly ApproveByPin $approveByPin,
        private readonly Transaction $transaction,
    ) {}

    public function handle(
        User $by,
        int $branchId,
        Money $openingCash,
        ?string $note = null,
        ?int $definitionId = null,
        ?string $businessDate = null,
        ?User $approver = null,
        ?string $approverPin = null,
        ?string $deviceKey = null,
    ): ShiftSession {
        return $this->transaction->run(function () use ($by, $branchId, $openingCash, $note, $definitionId, $businessDate, $approver, $approverPin, $deviceKey): ShiftSession {
            if ($this->currentShift->find($branchId) !== null) {
                throw ShiftAlreadyOpen::make();
            }

            if ($openingCash->isNegative()) {
                throw ActionRefused::because('The drawer count cannot be below zero.');
            }

            ['definition' => $proposed, 'business_date' => $proposedDate, 'last_closed' => $last] = $this->shifts->proposal($branchId);

            $definition = $definitionId === null || $definitionId === $proposed->id
                ? $proposed
                : ShiftDefinition::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('is_active', true)->findOrFail($definitionId);
            $date = $businessDate ?? $proposedDate;
            $changed = $definition->id !== $proposed->id || $date !== $proposedDate;

            if ($changed && $last !== null && ($approver === null || $approverPin === null || $deviceKey === null)) {
                throw ActionRefused::because('Changing the proposed shift needs a supervisor PIN.');
            }

            $counted = $last?->counted_cash;
            $note = $note === null || trim($note) === '' ? null : trim($note);

            if ($counted !== null && ! $counted->equals($openingCash) && $note === null) {
                throw ActionRefused::noteRequired('a drawer count different from the last count');
            }

            $now = $this->clock->now();

            try {
                $shift = ShiftSession::query()->create([
                    'branch_id' => $branchId,
                    'shift_definition_id' => $definition->id,
                    'period_name' => $definition->name,
                    'business_date' => $date,
                    'status' => ShiftStatus::Open,
                    'opened_at' => $now,
                    'opened_by' => $by->id,
                    'opening_cash' => $openingCash,
                    'opening_note' => $note === null ? null : mb_substr($note, 0, 255),
                ]);
            } catch (QueryException $e) {
                if (DuplicateKey::on($e, 'shift_sessions_one_open')) {
                    throw ShiftAlreadyOpen::make();
                }

                if (DuplicateKey::on($e, 'shift_sessions_period_unique')) {
                    throw ActionRefused::because("The {$definition->name} shift of {$date} has already been worked.");
                }

                throw $e;
            }

            ShiftMember::query()->create(['shift_session_id' => $shift->id, 'user_id' => $by->id, 'joined_at' => $now]);

            $handover = $last === null ? null : ShiftHandover::query()->where('from_shift_id', $last->id)->whereNull('to_shift_id')->first();
            $handover?->update([
                'to_shift_id' => $shift->id,
                'received_by' => $by->id,
                'received_cash' => $openingCash,
                'difference_amount' => $openingCash->minus($counted ?? $openingCash),
                'note' => $note === null ? null : mb_substr($note, 0, 255),
            ]);

            if ($changed && $last !== null && $approver !== null && $approverPin !== null && $deviceKey !== null) {
                $this->approveByPin->handle(
                    $approver, $approverPin, $deviceKey, $by, $branchId,
                    ApprovalAction::ChangeShiftPeriod, 'shift_session', $shift->id,
                    'Changed from the proposed period',
                    "{$proposed->name} {$proposedDate} → {$definition->name} {$date}",
                );
            }

            return $shift;
        });
    }
}
