<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Enums\CleaningTrigger;
use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\ProductKind;
use App\Enums\RoomStatusReason;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Exceptions\BalanceNotZero;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Room;
use App\Models\Stay;
use App\Models\StayRoom;
use App\Models\User;
use App\Queries\StayQuery;
use App\Services\CurrentShift;
use App\Services\RoomStatusService;
use App\Services\StayLedger;
use App\Support\Clock;
use App\Support\Money;
use App\Support\Transaction;

/**
 * Check a guest out (screens 16–18, DATABASE_SCHEMA §8.8, APP_FLOW §4.12).
 *
 * Damage lines for a key or remote not returned (priced from the branch) and for damage
 * products; the deposits pay the damages first (key deposit, then guest deposit); any payment
 * given is taken; a balance left refuses with BalanceNotZero. Each deposit still held is then
 * handed back, or, when the guest did not claim it, kept as income with a forfeited_deposit
 * line. The stay closes, the room goes to uncleaned with a cleaning task.
 *
 * A stay that is no longer checked in is returned as it is: a double click writes nothing.
 */
final class CheckOutGuest
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CurrentShift $currentShift,
        private readonly RoomStatusService $rooms,
        private readonly StayLedger $ledger,
        private readonly StayQuery $stays,
        private readonly Transaction $transaction,
    ) {}

    /**
     * @param  array<int, int>  $damages  damage product id → quantity
     * @param  bool  $depositClaimed  false when the guest did not claim what is left of the deposits
     */
    public function handle(
        User $by,
        Stay $stay,
        bool $keyReturned = true,
        bool $remoteReturned = true,
        array $damages = [],
        ?PaymentMethod $payWith = null,
        ?Money $amountGiven = null,
        ?string $referenceNo = null,
        bool $depositClaimed = true,
    ): Stay {
        $fresh = Stay::query()->withoutGlobalScopes()->findOrFail($stay->id);

        if ($fresh->state !== StayState::CheckedIn) {
            return $fresh;
        }

        return $this->transaction->run(function () use ($by, $fresh, $keyReturned, $remoteReturned, $damages, $payWith, $amountGiven, $referenceNo, $depositClaimed): Stay {
            // Lock order: shift → room → stay → products.
            $shift = $this->currentShift->forWrite($fresh->branch_id);
            $room = Room::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($fresh->room_id);
            $locked = Stay::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($fresh->id);

            if ($locked->state !== StayState::CheckedIn) {
                return $locked;
            }

            $branch = Branch::query()->findOrFail($locked->branch_id);
            $damageLines = [];

            foreach ([[$keyReturned, 'Lost key', $branch->lost_key_amount], [$remoteReturned, 'Lost remote', $branch->lost_remote_amount]] as [$returned, $label, $amount]) {
                if (! $returned && $amount !== null && $amount->isPositive()) {
                    $damageLines[] = $this->ledger->line($shift, $locked, $by, LineKind::Damage, $label, $amount)->id;
                }
            }

            $damages = array_filter($damages, static fn (int $qty): bool => $qty > 0);
            ksort($damages);

            if ($damages !== []) {
                $products = Product::query()->withoutGlobalScopes()
                    ->where('branch_id', $locked->branch_id)
                    ->where('kind', ProductKind::Damage)
                    ->whereIn('id', array_keys($damages))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($products->count() !== count($damages)) {
                    throw ActionRefused::because('Pick damages from the damage list.');
                }

                foreach ($damages as $id => $qty) {
                    $product = $products[$id];
                    $line = $this->ledger->line($shift, $locked, $by, LineKind::Damage, $product->name, $product->price_amount ?? Money::zero(), $qty, $product);
                    $this->ledger->stockOut($shift, $product, $qty, $line, $by);
                    $damageLines[] = $line->id;
                }
            }

            // Damages are covered by the key deposit first, then by the guest deposit.
            if ($damageLines !== []) {
                $this->ledger->applyDeposit($shift, $locked, $by, DepositType::Key, $damageLines);
                $this->ledger->applyDeposit($shift, $locked, $by, DepositType::Guest, $damageLines);
            }

            if ($payWith !== null && $amountGiven !== null) {
                $this->ledger->collect($shift, $locked, $by, $payWith, $amountGiven, $referenceNo);
            }

            if (! $this->stays->balanceDue($locked->id)->isZero()) {
                throw BalanceNotZero::make();
            }

            foreach ([DepositType::Key, DepositType::Guest] as $type) {
                $held = $this->stays->depositHeld($locked->id, $type);

                if (! $held->isPositive()) {
                    continue;
                }

                if ($depositClaimed) {
                    $this->ledger->refundDeposit($shift, $locked, $by, $type, 'returned at check-out');

                    continue;
                }

                $label = $type === DepositType::Key ? "Unclaimed {$branch->deposit_name} deposit" : 'Unclaimed guest deposit';
                $line = $this->ledger->line($shift, $locked, $by, LineKind::ForfeitedDeposit, $label, $held);
                $applied = $this->ledger->payment($shift, $locked, $by, PaymentKind::DepositApplied, PaymentMethod::Deposit, $held, $type);
                $this->ledger->allocate($applied, [[$line, $held]]);
            }

            $now = $this->clock->now();
            $locked->update([
                'state' => StayState::CheckedOut,
                'checked_out_at' => $now,
                'checkout_shift_id' => $shift->id,
                'checked_out_by' => $by->id,
            ]);
            StayRoom::query()->where('stay_id', $locked->id)->whereNull('to_at')->update(['to_at' => $now]);
            $room->update(['last_checkout_at' => $now]);
            $this->rooms->vacate($room, $locked, RoomStatusReason::CheckOut, CleaningTrigger::CheckOut, by: $by);

            return $locked;
        });
    }
}
