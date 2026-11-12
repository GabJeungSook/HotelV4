<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Enums\LineKind;
use App\Enums\PaymentMethod;
use App\Enums\PosMode;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Models\Branch;
use App\Models\PosOrder;
use App\Models\Product;
use App\Models\Stay;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\StayLedger;
use App\Support\Clock;
use App\Support\DuplicateKey;
use App\Support\Money;
use App\Support\Transaction;
use Illuminate\Database\QueryException;

/**
 * Charge products to a checked-in stay (sheet 10, DATABASE_SCHEMA §8.3, APP_FLOW §4.7).
 *
 * Locks the open shift, the stay and each product by id; refuses with InsufficientStock when a
 * stock-tracked product has fewer pieces than asked. Writes a room-charge pos_orders row, one
 * folio line per item at today's price, the stock movements and stock_on_hand. With a payment
 * ("Add and take payment now") it continues into TakePayment's step in the same transaction.
 */
final class AddCharges
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CurrentShift $currentShift,
        private readonly StayLedger $ledger,
        private readonly Transaction $transaction,
    ) {}

    /**
     * @param  array<int, int>  $items  product id → quantity
     */
    public function handle(
        User $by,
        Stay $stay,
        array $items,
        ?string $idempotencyKey = null,
        ?PaymentMethod $payWith = null,
        ?Money $amountGiven = null,
        ?string $referenceNo = null,
        bool $keepChangeAsDeposit = false,
    ): PosOrder {
        if ($idempotencyKey !== null && ($existing = $this->replay($idempotencyKey)) !== null) {
            return $existing;
        }

        $items = array_filter($items, static fn (int $qty): bool => $qty > 0);

        if ($items === []) {
            throw ActionRefused::because('Pick at least one item.');
        }

        try {
            return $this->transaction->run(function () use ($by, $stay, $items, $idempotencyKey, $payWith, $amountGiven, $referenceNo, $keepChangeAsDeposit): PosOrder {
                $shift = $this->currentShift->forPayment($stay->branch_id);
                $locked = Stay::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stay->id);

                if ($locked->state !== StayState::CheckedIn) {
                    throw ActionRefused::stayNotInHouse($locked->code);
                }

                ksort($items);
                $products = Product::query()->withoutGlobalScopes()
                    ->where('branch_id', $locked->branch_id)
                    ->whereIn('id', array_keys($items))
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($products->count() !== count($items)) {
                    throw ActionRefused::because('One of the items is no longer sold.');
                }

                $total = Money::zero();
                foreach ($items as $id => $qty) {
                    $total = $total->plus(($products[$id]->price_amount ?? Money::zero())->times($qty));
                }

                $now = $this->clock->now();
                $order = PosOrder::query()->create([
                    'branch_id' => $locked->branch_id,
                    'shift_session_id' => $shift->id,
                    'stay_id' => $locked->id,
                    'mode' => PosMode::RoomCharge,
                    'total_amount' => $total,
                    'created_by' => $by->id,
                    'order_no' => $this->nextOrderNo($locked->branch_id),
                    'idempotency_key' => $idempotencyKey,
                    'occurred_at' => $now,
                    'created_at' => $now,
                ]);

                foreach ($items as $id => $qty) {
                    $product = $products[$id];
                    $line = $this->ledger->line(
                        $shift, $locked, $by, LineKind::from($product->kind->value), $product->name,
                        $product->price_amount ?? Money::zero(), $qty, $product, $order,
                    );
                    $this->ledger->stockOut($shift, $product, $qty, $line, $by);
                }

                if ($payWith !== null) {
                    $this->ledger->collect($shift, $locked, $by, $payWith, $amountGiven ?? $total, $referenceNo, $keepChangeAsDeposit);
                }

                return $order;
            });
        } catch (QueryException $e) {
            if ($idempotencyKey !== null && DuplicateKey::on($e, 'pos_orders_idempotency_unique')) {
                return $this->replay($idempotencyKey) ?? throw $e;
            }

            throw $e;
        }
    }

    /** P-008821 from branches.next_pos_number, the branch row locked last (DATABASE_SCHEMA §4.1). */
    private function nextOrderNo(int $branchId): string
    {
        $branch = Branch::query()->lockForUpdate()->findOrFail($branchId);
        $number = $branch->next_pos_number;
        $branch->update(['next_pos_number' => $number + 1]);

        return sprintf('P-%06d', $number);
    }

    private function replay(string $idempotencyKey): ?PosOrder
    {
        return PosOrder::query()->withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();
    }
}
