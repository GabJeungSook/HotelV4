<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Actions\Stays\AddCharges;
use App\Enums\PaymentKind;
use App\Enums\PosMode;
use App\Exceptions\ActionRefused;
use App\Models\PosOrder;
use App\Models\Product;
use App\Models\Stay;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\PosLedger;
use App\Services\PosOrderNumber;
use App\Services\StayLedger;
use App\Support\Clock;
use App\Support\DuplicateKey;
use App\Support\Money;
use App\Support\Transaction;
use Illuminate\Database\QueryException;

/**
 * A POS sale (screen 19, APP_FLOW §4.13).
 *
 * Walk-in · cash: paid in full. Locks the open shift and each product by id; InsufficientStock
 * if an item is short (the whole sale rolls back). Writes pos_orders (walk_in), one folio line
 * per item with pos_order_id and no stay, the stock movements, one cash payment tied to the
 * order with tendered and change, and its allocations.
 *
 * Charge to room: the same as Add charge (10), flow 4.7 — handed to AddCharges.
 *
 * The form's idempotency key is stored on pos_orders; a repeated key returns the first order.
 */
final class CompleteSale
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CurrentShift $currentShift,
        private readonly StayLedger $stays,
        private readonly PosLedger $pos,
        private readonly PosOrderNumber $orderNumber,
        private readonly AddCharges $addCharges,
        private readonly Transaction $transaction,
    ) {}

    /**
     * @param  array<int, int>  $items  product id → quantity
     */
    public function handle(
        User $by,
        int $branchId,
        PosMode $mode,
        array $items,
        string $idempotencyKey,
        ?Money $tendered = null,
        ?Stay $stay = null,
    ): PosOrder {
        if (($existing = $this->replay($idempotencyKey)) !== null) {
            return $existing;
        }

        if ($mode === PosMode::RoomCharge) {
            if ($stay === null || $stay->branch_id !== $branchId) {
                throw ActionRefused::because('Pick the occupied room to charge.');
            }

            return $this->addCharges->handle($by, $stay, $items, $idempotencyKey);
        }

        $items = array_filter($items, static fn (int $qty): bool => $qty > 0);

        if ($items === []) {
            throw ActionRefused::because('Pick at least one item.');
        }

        if ($tendered === null || ! $tendered->isPositive()) {
            throw ActionRefused::because('Type the cash received.');
        }

        try {
            return $this->transaction->run(function () use ($by, $branchId, $items, $idempotencyKey, $tendered): PosOrder {
                $shift = $this->currentShift->forPayment($branchId);

                ksort($items);
                $products = Product::query()->withoutGlobalScopes()
                    ->where('branch_id', $branchId)
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

                if ($tendered->lessThan($total)) {
                    throw ActionRefused::notEnoughTendered();
                }

                $now = $this->clock->now();
                $order = PosOrder::query()->create([
                    'branch_id' => $branchId,
                    'shift_session_id' => $shift->id,
                    'stay_id' => null,
                    'mode' => PosMode::WalkIn,
                    'total_amount' => $total,
                    'created_by' => $by->id,
                    'order_no' => $this->orderNumber->next($branchId),
                    'idempotency_key' => $idempotencyKey,
                    'occurred_at' => $now,
                    'created_at' => $now,
                ]);

                $allocations = [];
                foreach ($items as $id => $qty) {
                    $product = $products[$id];
                    $line = $this->pos->line($shift, $order, $by, $product, $product->price_amount ?? Money::zero(), $qty);
                    $this->stays->stockOut($shift, $product, $qty, $line, $by);
                    $allocations[] = [$line, $line->amount ?? Money::zero()];
                }

                $payment = $this->pos->cash($shift, $order, $by, PaymentKind::Payment, $total, $tendered, $tendered->minus($total));
                $this->stays->allocate($payment, $allocations);

                return $order;
            });
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'pos_orders_idempotency_unique')) {
                return $this->replay($idempotencyKey) ?? throw $e;
            }

            throw $e;
        }
    }

    /** @phpstan-impure */
    private function replay(string $idempotencyKey): ?PosOrder
    {
        return PosOrder::query()->withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();
    }
}
