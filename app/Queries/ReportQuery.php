<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\StockMovementKind;
use App\Models\Product;
use App\Models\ShiftSession;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Read-side ledger queries for reports (DATABASE_SCHEMA §7). Phase 6 adds `stock`, used by the
 * stock screen (20) and later by the inventory report (42).
 */
final class ReportQuery
{
    /**
     * Stock per product for one shift (20, APP_FLOW §4.14): opening, in, sold, wastage and
     * closing, where closing = opening + in − sold − wastage and a shift's closing is the next
     * shift's opening (TRD §10, Reports).
     *
     * A movement belongs to the shift it is stamped with; an adjustment outside any shift
     * (shift_session_id NULL) counts in the shift that was open when it happened. Opening is
     * worked back from stock_on_hand, so stock seeded without a movement still has an opening.
     * "In" is receipts plus adjustments; "sold" is sales less sale reversals.
     *
     * @return list<array{product_id: int, name: string, opening: int, in: int, sold: int, wastage: int, closing: int, low_at: int}>
     */
    public function stock(ShiftSession $shift): array
    {
        $branchId = $shift->branch_id;
        $openedAt = $shift->opened_at;
        $closedAt = $shift->closed_at;

        // Everything that moved from the start of this shift on: this shift, later shifts, and
        // movements outside any shift since this one opened.
        $later = DB::table('stock_movements')
            ->where('branch_id', $branchId)
            ->where(fn ($q) => $q->where('shift_session_id', '>=', $shift->id)
                ->orWhere(fn ($q) => $q->whereNull('shift_session_id')->where('occurred_at', '>=', $openedAt)))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) AS qty')
            ->pluck('qty', 'product_id');

        $during = DB::table('stock_movements')
            ->where('branch_id', $branchId)
            ->where(fn ($q) => $q->where('shift_session_id', $shift->id)
                ->orWhere(fn ($q) => $q->whereNull('shift_session_id')->where('occurred_at', '>=', $openedAt)
                    ->when($closedAt !== null, fn ($q) => $q->where('occurred_at', '<', $closedAt))))
            ->groupBy('product_id', 'kind')
            ->selectRaw('product_id, kind, SUM(quantity) AS qty')
            ->get();

        $byKind = [];
        foreach ($during as $row) {
            $byKind[(int) $row->product_id][(string) $row->kind] = (int) $row->qty;
        }

        $products = Product::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('tracks_stock', true)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', array_keys($byKind)))
            ->orderBy('name')
            ->get(['id', 'name', 'stock_on_hand', 'low_stock_at']);

        $rows = [];
        foreach ($products as $product) {
            $k = $byKind[$product->id] ?? [];
            $in = ($k[StockMovementKind::Receive->value] ?? 0) + ($k[StockMovementKind::Adjustment->value] ?? 0);
            $sold = -(($k[StockMovementKind::Sale->value] ?? 0) + ($k[StockMovementKind::SaleReversal->value] ?? 0));
            $wastage = -($k[StockMovementKind::Wastage->value] ?? 0);
            $opening = $product->stock_on_hand - (int) ($later[$product->id] ?? 0);

            $rows[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'opening' => $opening,
                'in' => $in,
                'sold' => $sold,
                'wastage' => $wastage,
                'closing' => $opening + $in - $sold - $wastage,
                'low_at' => $product->low_stock_at,
            ];
        }

        return $rows;
    }

    /**
     * The movements behind the stock figures of one shift, newest first ("each number opens the
     * list of movements behind it", 20).
     *
     * @return list<array{id: int, product_id: int, kind: string, quantity: int, balance_after: int, occurred_at: string, by: string|null, reason: string|null, note: string|null}>
     */
    public function stockMovements(ShiftSession $shift): array
    {
        return array_values(StockMovement::query()->withoutGlobalScopes()
            ->with(['creator:id,name', 'reason:id,name'])
            ->where('branch_id', $shift->branch_id)
            ->where('shift_session_id', $shift->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (StockMovement $m): array => [
                'id' => $m->id,
                'product_id' => $m->product_id,
                'kind' => $m->kind->value,
                'quantity' => $m->quantity,
                'balance_after' => $m->balance_after,
                'occurred_at' => $m->occurred_at->toIso8601String(),
                'by' => $m->creator?->name,
                'reason' => $m->reason?->name,
                'note' => $m->note,
            ])
            ->all());
    }
}
