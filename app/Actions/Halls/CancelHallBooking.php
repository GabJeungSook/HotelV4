<?php

declare(strict_types=1);

namespace App\Actions\Halls;

use App\Actions\Auth\ApproveByPin;
use App\Enums\ApprovalAction;
use App\Enums\HallBookingStatus;
use App\Exceptions\ActionRefused;
use App\Models\HallBooking;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\HallLedger;
use App\Support\Transaction;

/**
 * Cancel a reserved hall booking with a supervisor PIN (DATABASE_SCHEMA §8.14): writes the approval
 * (cancel_hall_booking), settles the deposit on the open shift — handed back as a deposit_refund or
 * kept as a forfeited_deposit line — and marks the booking cancelled with its reason.
 */
final class CancelHallBooking
{
    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly HallLedger $ledger,
        private readonly ApproveByPin $approveByPin,
        private readonly Transaction $transaction,
    ) {}

    public function handle(
        User $by,
        HallBooking $booking,
        string $reason,
        User $approver,
        string $approverPin,
        string $deviceKey,
        bool $refund = true,
    ): HallBooking {
        $reason = trim($reason);

        if ($reason === '') {
            throw ActionRefused::because('Give a reason for the cancellation.');
        }

        return $this->transaction->run(function () use ($by, $booking, $reason, $approver, $approverPin, $deviceKey, $refund): HallBooking {
            $shift = $this->currentShift->forWrite($booking->branch_id);
            $locked = HallBooking::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($booking->id);

            if ($locked->status !== HallBookingStatus::Reserved) {
                throw ActionRefused::because("{$locked->code} is not a reserved booking that can be cancelled.");
            }

            $held = $this->ledger->depositHeld($locked->id);
            $money = $held->isPositive() ? ($refund ? ' · refund '.$held->format() : ' · keep '.$held->format()) : '';

            $approval = $this->approveByPin->handle(
                $approver, $approverPin, $deviceKey, $by, $locked->branch_id,
                ApprovalAction::CancelHallBooking, 'hall_booking', $locked->id, $reason,
                "{$locked->code} cancelled{$money}",
            );

            $this->ledger->settleGuestDeposit($shift, $locked, $by, $refund, $refund ? 'booking cancelled, deposit returned' : 'booking cancelled, deposit kept', $approval->id);
            $locked->update(['status' => HallBookingStatus::Cancelled, 'cancel_reason' => mb_substr($reason, 0, 255)]);

            return $locked;
        });
    }
}
