<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Enums\StockMovementKind;
use App\Exceptions\ActionRefused;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\StockBook;
use App\Support\Transaction;

/**
 * Receive stock (form on screen 20, APP_FLOW §4.14): locks the open shift and the product,
 * writes stock_movements kind receive with a positive quantity and balance_after, stamped
 * with the shift and the person, and products.stock_on_hand in the same transaction.
 */
final class ReceiveStock
{
    public const MAX_PIECES = 9999;

    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly StockBook $stock,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $by, int $branchId, int $productId, int $quantity, ?string $note = null): StockMovement
    {
        if ($quantity < 1 || $quantity > self::MAX_PIECES) {
            throw ActionRefused::because('Type how many pieces came in (1 to '.self::MAX_PIECES.').');
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        return $this->transaction->run(function () use ($by, $branchId, $productId, $quantity, $note): StockMovement {
            $shift = $this->currentShift->forWrite($branchId);
            $product = Product::query()->withoutGlobalScopes()->where('branch_id', $branchId)->lockForUpdate()->findOrFail($productId);

            if (! $product->is_active || ! $product->tracks_stock) {
                throw ActionRefused::because("{$product->name} does not keep stock.");
            }

            return $this->stock->move($shift, $product, StockMovementKind::Receive, $quantity, $by, note: $note);
        });
    }
}
