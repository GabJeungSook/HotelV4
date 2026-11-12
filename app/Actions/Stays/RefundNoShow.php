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
 * Settle the money paid ahead on a no-show (detail sheet on 57, sheet 14, APP_FLOW §4.19,
 * TRD §4.9): with a reason (the `cancel_reservation` list) and a supervisor's PIN, the guest
 * deposit held is handed back as a deposit_refund on the open shift, or kept as a
 * forfeited_deposit line paid by a deposit_applied. The stay stays `no_show`; once settled it
 * can no longer be checked in from the reservation.
 */
final class RefundNoShow
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

            if ($locked->state !== StayState::NoShow) {
                throw ActionRefused::because("{$locked->code} is not a no-show.");
            }

            $held = $this->stays->depositHeld($locked->id, DepositType::Guest);

            if (! $held->isPositive()) {
                throw ActionRefused::because("There is no money paid ahead on {$locked->code} to settle.");
            }

            $approval = $this->approveByPin->handle(
                $approver, $approverPin, $deviceKey, $by, $locked->branch_id,
                ApprovalAction::RefundNoShow, 'stay', $locked->id, $text,
                "{$locked->code} no-show · ".($refund ? 'refund ' : 'keep ').$held->format(),
                $reason->id,
            );

            $this->ledger->settleGuestDeposit($shift, $locked, $by, $refund, $refund ? 'no-show, paid ahead returned' : 'no-show, paid ahead kept', $approval->id);

            return $locked;
        });
    }
}
