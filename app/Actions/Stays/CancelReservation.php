<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Actions\Auth\ApproveByPin;
use App\Enums\ApprovalAction;
use App\Enums\DepositType;
use App\Enums\ReasonKind;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Models\Reason;
use App\Models\Stay;
use App\Models\User;
use App\Queries\StayQuery;
use App\Services\CurrentShift;
use App\Services\StayLedger;
use App\Support\ReasonText;
use App\Support\Transaction;

/**
 * Cancel a reservation from its detail sheet on 57, with a reason from the
 * `cancel_reservation` list and a supervisor's PIN (sheet 14, APP_FLOW §4.19, TRD §4.9).
 *
 * Writes the approval (cancel_reservation), settles any money paid ahead on the open shift —
 * handed back as a deposit_refund, or kept as a forfeited_deposit line paid by a deposit_applied
 * — and sets the stay to cancelled with the reason. The room was never moved, so nothing else
 * changes.
 */
final class CancelReservation
{
    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly StayLedger $ledger,
        private readonly StayQuery $stays,
        private readonly ApproveByPin $approveByPin,
        private readonly Transaction $transaction,
    ) {}

    public function handle(
        User $by,
        Stay $stay,
        Reason $reason,
        User $approver,
        string $approverPin,
        string $deviceKey,
        ?string $note = null,
        bool $refund = true,
    ): Stay {
        $text = ReasonText::for($reason, ReasonKind::CancelReservation, $note);

        return $this->transaction->run(function () use ($by, $stay, $reason, $approver, $approverPin, $deviceKey, $text, $refund): Stay {
            // Lock order: shift → stay.
            $shift = $this->currentShift->forWrite($stay->branch_id);
            $locked = Stay::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stay->id);

            if ($locked->state !== StayState::Reserved) {
                throw ActionRefused::because("{$locked->code} is not a reservation that can be cancelled.");
            }

            $held = $this->stays->depositHeld($locked->id, DepositType::Guest);
            $money = $held->isPositive() ? ($refund ? ' · refund '.$held->format() : ' · keep '.$held->format()) : '';

            $approval = $this->approveByPin->handle(
                $approver, $approverPin, $deviceKey, $by, $locked->branch_id,
                ApprovalAction::CancelReservation, 'stay', $locked->id, $text,
                "{$locked->code} cancelled{$money}",
                $reason->id,
            );

            $this->ledger->settleGuestDeposit($shift, $locked, $by, $refund, $refund ? 'reservation cancelled, paid ahead returned' : 'reservation cancelled, paid ahead kept', $approval->id);
            $locked->update(['state' => StayState::Cancelled, 'closed_reason' => mb_substr($text, 0, 255)]);

            return $locked;
        });
    }
}
