<?php

declare(strict_types=1);

namespace App\Actions\Halls;

use App\Enums\DepositType;
use App\Enums\HallBookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Exceptions\ActionRefused;
use App\Models\HallBooking;
use App\Models\Payment;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\HallLedger;
use App\Support\DuplicateKey;
use App\Support\Money;
use App\Support\Transaction;
use Illuminate\Database\QueryException;

/**
 * Take another deposit instalment on a reserved hall booking (DATABASE_SCHEMA §8.14): a guest
 * deposit_in on the open shift. Deposits may not exceed the quoted total. A repeated key replays.
 */
final class AddHallDeposit
{
    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly HallLedger $ledger,
        private readonly Transaction $transaction,
    ) {}

    public function handle(
        User $by,
        HallBooking $booking,
        PaymentMethod $method,
        Money $amount,
        ?string $referenceNo = null,
        ?string $idempotencyKey = null,
    ): Payment {
        if ($idempotencyKey !== null && ($existing = $this->replay($idempotencyKey)) !== null) {
            return $existing;
        }

        if (! $amount->isPositive()) {
            throw ActionRefused::because('Type the amount received.');
        }

        if ($method === PaymentMethod::Deposit) {
            throw ActionRefused::because('A deposit is paid in cash, GCash or card.');
        }

        try {
            return $this->transaction->run(function () use ($by, $booking, $method, $amount, $referenceNo, $idempotencyKey): Payment {
                $shift = $this->currentShift->forPayment($booking->branch_id);
                $locked = HallBooking::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($booking->id);

                if ($locked->status !== HallBookingStatus::Reserved) {
                    throw ActionRefused::because("{$locked->code} is not a reserved booking.");
                }

                $this->ledger->assertAccepted($locked->branch_id, $method, $referenceNo);

                $held = $this->ledger->depositHeld($locked->id);

                if ($held->plus($amount)->greaterThan($locked->quoted_amount ?? Money::zero())) {
                    throw ActionRefused::because('The deposits cannot be more than the quoted total.');
                }

                return $this->ledger->payment($shift, $locked, $by, PaymentKind::DepositIn, $method, $amount, DepositType::Guest, referenceNo: $referenceNo, note: 'deposit', idempotencyKey: $idempotencyKey);
            });
        } catch (QueryException $e) {
            if ($idempotencyKey !== null && DuplicateKey::on($e, 'payments_idempotency_unique')) {
                return $this->replay($idempotencyKey) ?? throw $e;
            }

            throw $e;
        }
    }

    private function replay(string $idempotencyKey): ?Payment
    {
        return Payment::query()->withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();
    }
}
