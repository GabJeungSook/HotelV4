<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Enums\DepositType;
use App\Enums\PaymentMethod;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Models\Payment;
use App\Models\Stay;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\StayLedger;
use App\Support\Money;
use App\Support\Transaction;

/**
 * Take a payment on a folio (sheet 11, DATABASE_SCHEMA §8.4, APP_FLOW §4.8).
 *
 * Applies the smaller of the amount given and the balance due, oldest unpaid line first.
 * Cash above the balance is change, or a guest deposit when the guest keeps it. Paying from
 * deposit uses the guest deposit only and is refused above what is held; the key deposit is
 * only used at check-out.
 */
final class TakePayment
{
    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly StayLedger $ledger,
        private readonly Transaction $transaction,
    ) {}

    public function handle(
        User $by,
        Stay $stay,
        PaymentMethod $method,
        Money $amount,
        ?string $referenceNo = null,
        bool $keepChangeAsDeposit = false,
        DepositType $fromDeposit = DepositType::Guest,
    ): Payment {
        return $this->transaction->run(function () use ($by, $stay, $method, $amount, $referenceNo, $keepChangeAsDeposit, $fromDeposit): Payment {
            $shift = $this->currentShift->forPayment($stay->branch_id);
            $locked = Stay::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stay->id);

            if ($locked->state !== StayState::CheckedIn) {
                throw ActionRefused::stayNotInHouse($locked->code);
            }

            return $this->ledger->collect($shift, $locked, $by, $method, $amount, $referenceNo, $keepChangeAsDeposit, $fromDeposit)
                ?? throw ActionRefused::nothingToPay();
        });
    }
}
