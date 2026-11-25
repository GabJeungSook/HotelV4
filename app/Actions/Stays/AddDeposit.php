<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Enums\DepositType;
use App\Enums\PaymentKind;
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
 * Add money to a guest's deposit on the folio (9): the guest hands over cash (or GCash) to keep on
 * their account, to spend on later charges (Take payment → Guest deposit) and claim the rest at
 * check-out. Writes one deposit_in payment of type guest — it is not a charge and pays no line.
 *
 * A deposit cannot be funded from a deposit, and never on a stay that is not checked in. The form's
 * idempotency key goes on the payment row: a repeated key returns the first payment and writes
 * nothing (APP_FLOW §6 case 9), like every other money action.
 */
final class AddDeposit
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
        ?string $idempotencyKey = null,
    ): Payment {
        $existing = $this->replay($idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        if (! $amount->isPositive()) {
            throw ActionRefused::because('Type the amount to add to the deposit.');
        }

        if ($method === PaymentMethod::Deposit) {
            throw ActionRefused::because('A deposit cannot be funded from a deposit.');
        }

        try {
            return $this->transaction->run(function () use ($by, $stay, $method, $amount, $referenceNo, $idempotencyKey): Payment {
                $shift = $this->currentShift->forPayment($stay->branch_id);
                $locked = Stay::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stay->id);

                if ($locked->state !== StayState::CheckedIn) {
                    throw ActionRefused::stayNotInHouse($locked->code);
                }

                $this->ledger->assertAccepted($locked, $method, $referenceNo);

                return $this->ledger->payment(
                    $shift,
                    $locked,
                    $by,
                    PaymentKind::DepositIn,
                    $method,
                    $amount,
                    DepositType::Guest,
                    referenceNo: $referenceNo,
                    note: 'deposit added',
                    idempotencyKey: $idempotencyKey,
                );
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
