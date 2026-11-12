<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StockMovementKind;
use App\Models\FolioLine;
use App\Models\Product;
use App\Models\ShiftSession;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Clock;

/**
 * The one writer of stock_movements (DATABASE_SCHEMA §4.13): a signed movement with its
 * balance_after, and products.stock_on_hand set to that balance in the same transaction. The
 * caller has locked the product (DATABASE_SCHEMA §8 lock order) and checked that the balance
 * stays at zero or above (stock_movements_balance_chk refuses the rest).
 */
final class StockBook
{
    public function __construct(private readonly Clock $clock) {}

    public function move(
        ShiftSession $shift,
        Product $product,
        StockMovementKind $kind,
        int $quantity,
        User $by,
        ?FolioLine $line = null,
        ?int $reasonId = null,
        ?string $note = null,
    ): StockMovement {
        $balance = $product->stock_on_hand + $quantity;
        $now = $this->clock->now();

        $movement = StockMovement::query()->create([
            'branch_id' => $product->branch_id,
            'product_id' => $product->id,
            'shift_session_id' => $shift->id,
            'kind' => $kind,
            'quantity' => $quantity,
            'balance_after' => $balance,
            'folio_line_id' => $line?->id,
            'reason_id' => $reasonId,
            'note' => $note === null ? null : mb_substr($note, 0, 255),
            'created_by' => $by->id,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);

        $product->update(['stock_on_hand' => $balance]);

        return $movement;
    }
}
