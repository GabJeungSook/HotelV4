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
use App\Support\DuplicateKey;
use App\Support\Money;
use App\Support\Transaction;
use Illuminate\Database\QueryException;

/**
 * Take a payment on a folio (sheet 11, DATABASE_SCHEMA §8.4, APP_FLOW §4.8).
 *
 * Applies the smaller of the amount given and the balance due, oldest unpaid line first.
 * Cash above the balance is change, or a guest deposit when the guest keeps it. Paying from
 * deposit uses the guest deposit only and is refused above what is held; the key deposit is
 * only used at check-out.
 *
 * The form's idempotency key goes on the payment row (migration 000032): a repeated key
 * returns the first payment and writes nothing (APP_FLOW §6 case 9).
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
        ?string $idempotencyKey = null,
    ): Payment {
        $existing = $this->replay($idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->transaction->run(function () use ($by, $stay, $method, $amount, $referenceNo, $keepChangeAsDeposit, $fromDeposit, $idempotencyKey): Payment {
                $shift = $this->currentShift->forPayment($stay->branch_id);
                $locked = Stay::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stay->id);

                if ($locked->state !== StayState::CheckedIn) {
                    throw ActionRefused::stayNotInHouse($locked->code);
                }

                return $this->ledger->collect($shift, $locked, $by, $method, $amount, $referenceNo, $keepChangeAsDeposit, $fromDeposit, $idempotencyKey)
                    ?? throw ActionRefused::nothingToPay();
            });
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'payments_idempotency_unique')) {
                return $this->replay($idempotencyKey) ?? throw $e;
            }

            throw $e;
        }
    }

    private function replay(?string $idempotencyKey): ?Payment
    {
        if ($idempotencyKey === null) {
            return null;
        }

        return Payment::query()->withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();
    }
}
