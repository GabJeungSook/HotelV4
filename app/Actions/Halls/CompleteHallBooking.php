<?php

declare(strict_types=1);

namespace App\Actions\Halls;

use App\Enums\HallBookingStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\ActionRefused;
use App\Exceptions\BalanceNotZero;
use App\Models\HallBooking;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\HallLedger;
use App\Support\Money;
use App\Support\Transaction;

/**
 * Complete a reserved hall booking (the event happened): post one folio line per quote item
 * (kind event_hall) on the open shift, apply the guest deposit held, collect the balance, and mark
 * the booking completed. Refuses if a balance is left (DATABASE_SCHEMA §8.14).
 */
final class CompleteHallBooking
{
    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly HallLedger $ledger,
        private readonly Transaction $transaction,
    ) {}

    public function handle(
        User $by,
        HallBooking $booking,
        ?PaymentMethod $payWith = null,
        ?Money $amountGiven = null,
        ?string $referenceNo = null,
    ): HallBooking {
        return $this->transaction->run(function () use ($by, $booking, $payWith, $amountGiven, $referenceNo): HallBooking {
            $shift = $this->currentShift->forPayment($booking->branch_id);
            $locked = HallBooking::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($booking->id);

            if ($locked->status !== HallBookingStatus::Reserved) {
                throw ActionRefused::because("{$locked->code} is not a reserved booking.");
            }

            $items = $locked->items()->orderBy('id')->get();

            if ($items->isEmpty()) {
                throw ActionRefused::because('This booking has no items to charge.');
            }

            foreach ($items as $item) {
                $this->ledger->line($shift, $locked, $by, $item->label, $item->unit_amount ?? Money::zero(), $item->quantity);
            }

            $this->ledger->applyGuestDeposit($shift, $locked, $by);

            if ($payWith !== null && $amountGiven !== null) {
                $this->ledger->collect($shift, $locked, $by, $payWith, $amountGiven, $referenceNo);
            }

            if (! $this->ledger->balanceDue($locked->id)->isZero()) {
                throw BalanceNotZero::make();
            }

            $locked->update(['status' => HallBookingStatus::Completed, 'complete_shift_id' => $shift->id]);

            return $locked;
        });
    }
}
