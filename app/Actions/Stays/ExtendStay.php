<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Enums\LineKind;
use App\Enums\PaymentMethod;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Models\Branch;
use App\Models\FolioLine;
use App\Models\Stay;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\Pricing\RateCard;
use App\Services\PricingService;
use App\Services\StayLedger;
use App\Support\Money;
use App\Support\Transaction;

/**
 * Extend a stay (screen 12, DATABASE_SCHEMA §8.5, APP_FLOW §4.9). PricingService gives the
 * amount and the new cycle_hours_used (TRD §4.3); an extension line is written and the
 * expected check-out moves by the hours bought. With a payment it continues into
 * TakePayment's step in the same transaction.
 */
final class ExtendStay
{
    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly PricingService $pricing,
        private readonly StayLedger $ledger,
        private readonly Transaction $transaction,
    ) {}

    public function handle(
        User $by,
        Stay $stay,
        int $hours,
        ?PaymentMethod $payWith = null,
        ?Money $amountGiven = null,
        ?string $referenceNo = null,
        bool $keepChangeAsDeposit = false,
    ): FolioLine {
        return $this->transaction->run(function () use ($by, $stay, $hours, $payWith, $amountGiven, $referenceNo, $keepChangeAsDeposit): FolioLine {
            $shift = $this->currentShift->forPayment($stay->branch_id);
            $locked = Stay::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stay->id);

            if ($locked->state !== StayState::CheckedIn || $locked->expected_checkout_at === null) {
                throw ActionRefused::stayNotInHouse($locked->code);
            }

            $quote = $this->pricing->quoteExtension(
                RateCard::forBranch(Branch::query()->findOrFail($locked->branch_id)),
                $locked->room_type_id, $locked->stay_kind, $locked->cycle_hours_used, $hours,
            );

            $line = $this->ledger->line($shift, $locked, $by, LineKind::Extension, "Extension {$hours} hrs", $quote->amount);

            $locked->update([
                'expected_checkout_at' => $locked->expected_checkout_at->addHours($hours),
                'cycle_hours_used' => $quote->cycleHoursUsed,
            ]);

            if ($payWith !== null) {
                $this->ledger->collect($shift, $locked, $by, $payWith, $amountGiven ?? $quote->amount, $referenceNo, $keepChangeAsDeposit);
            }

            return $line;
        });
    }
}
