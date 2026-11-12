<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Actions\Auth\ApproveByPin;
use App\Enums\ApprovalAction;
use App\Enums\ReasonKind;
use App\Enums\ShiftStatus;
use App\Exceptions\ActionRefused;
use App\Models\CashMovement;
use App\Models\Reason;
use App\Models\User;
use App\Services\CurrentShift;
use App\Support\Clock;
use App\Support\ReasonText;
use App\Support\Transaction;

/**
 * Void a wrong cash entry with a reason and a supervisor PIN (21, sheet 14; APP_FLOW §4.15).
 * Sets the void columns, the only update allowed on cash_movements; the row stays in the list,
 * struck through, and counts as ₱0.00. Allowed only while the entry's shift is `open`: a closed
 * shift's entry is refused, and so is any entry once the close has started (ShiftClosing).
 */
final class VoidCashMovement
{
    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly ApproveByPin $approveByPin,
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $by, int $branchId, int $movementId, Reason $reason, ?string $note, User $approver, string $pin, string $deviceKey): CashMovement
    {
        return $this->transaction->run(function () use ($by, $branchId, $movementId, $reason, $note, $approver, $pin, $deviceKey): CashMovement {
            $movement = CashMovement::query()->withoutGlobalScopes()->where('branch_id', $branchId)->findOrFail($movementId);
            $open = $this->currentShift->find($branchId);

            if ($open === null || $open->id !== $movement->shift_session_id) {
                throw ActionRefused::because('This entry belongs to a closed shift and cannot be voided. Correct it on the open shift.');
            }

            $shift = $this->currentShift->forWrite($branchId);

            if ($shift->status !== ShiftStatus::Open) {
                throw ActionRefused::because('Entries can be voided only while the shift is open.');
            }

            $locked = CashMovement::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($movement->id);

            if ($locked->voided_at !== null) {
                throw ActionRefused::because('This entry is already voided.');
            }

            $text = ReasonText::for($reason, ReasonKind::VoidCashMovement, $note);
            $label = ucfirst(str_replace('_', ' ', $locked->kind->value));

            $approval = $this->approveByPin->handle(
                $approver, $pin, $deviceKey, $by, $locked->branch_id,
                ApprovalAction::VoidCashMovement, 'cash_movement', $locked->id,
                $text, "{$label} · {$locked->note} · −".($locked->amount?->format() ?? ''), $reason->id,
            );

            $locked->update([
                'voided_at' => $this->clock->now(),
                'voided_by' => $by->id,
                'void_reason' => mb_substr($text, 0, 255),
                'reason_id' => $reason->id,
                'approval_id' => $approval->id,
            ]);

            return $locked;
        });
    }
}
