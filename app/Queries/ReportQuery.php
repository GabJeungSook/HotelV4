<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CashMovementKind;
use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\StockMovementKind;
use App\Models\CashMovement;
use App\Models\FolioLine;
use App\Models\Payment;
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

    /**
     * Stock per product for one business day (42): the movements of the day's shifts, plus those
     * outside any shift inside the day's window. Opening is worked back from stock_on_hand less
     * every movement from the day's first shift on, so one day's closing is the next day's
     * opening (TRD §10, Reports).
     *
     * @param  list<int>  $shiftIds
     * @return list<array{product_id: int, name: string, opening: int, in: int, sold: int, wastage: int, closing: int, low_at: int}>
     */
    public function stockForDay(int $branchId, array $shiftIds, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $first = $shiftIds === [] ? null : min($shiftIds);

        $later = DB::table('stock_movements')
            ->where('branch_id', $branchId)
            ->where(fn ($q) => $q->when($first !== null, fn ($q) => $q->where('shift_session_id', '>=', $first), fn ($q) => $q->whereRaw('0 = 1'))
                ->orWhere(fn ($q) => $q->whereNull('shift_session_id')->where('occurred_at', '>=', $start))
                ->when($first === null, fn ($q) => $q->orWhere(fn ($q) => $q->whereNotNull('shift_session_id')->where('occurred_at', '>=', $start))))
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(quantity) AS qty')
            ->pluck('qty', 'product_id');

        $during = DB::table('stock_movements')
            ->where('branch_id', $branchId)
            ->where(fn ($q) => $q->whereIn('shift_session_id', $shiftIds)
                ->orWhere(fn ($q) => $q->whereNull('shift_session_id')->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)))
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
     * The ledger rows behind one figure of the shift reports (37, 37b, 38): "every figure can
     * be clicked to list the ledger lines behind it". Their amounts add up to the figure.
     *
     * @param  list<int>  $shiftIds
     * @return array{title: string, rows: list<array<string, mixed>>, total: int}
     */
    public function ledger(array $shiftIds, string $figure): array
    {
        [$name, $arg] = array_pad(explode(':', $figure, 2), 2, null);
        $lineKinds = [
            'room' => [LineKind::Room, LineKind::Discount], 'extension' => [LineKind::Extension], 'transfer_difference' => [LineKind::TransferDifference],
            'food_drink' => [LineKind::FoodDrink], 'amenity' => [LineKind::Amenity], 'damage' => [LineKind::Damage], 'forfeited_deposit' => [LineKind::ForfeitedDeposit],
        ];

        $result = match ($name) {
            'sales' => ['Sales · '.str_replace('_', ' ', (string) $arg), $this->lines($shiftIds, fn ($q) => $q->whereIn('kind', $lineKinds[$arg] ?? []))],
            'gross' => ['Gross sales', $this->lines($shiftIds, fn ($q) => $q->where('kind', '!=', LineKind::Discount))],
            'net' => ['Net sales', $this->lines($shiftIds, fn ($q) => $q)],
            'discount' => ["Discount · {$arg}", $this->lines($shiftIds, fn ($q) => $q->where('kind', LineKind::Discount)->where('description', $arg))],
            'collected' => ['Collected', $this->payments($shiftIds, fn ($q) => $q->whereIn('kind', self::BILL_KINDS), applied: true)],
            'method' => ['Paid by '.$arg, $this->payments($shiftIds, fn ($q) => $q->whereIn('kind', self::BILL_KINDS)->where('method', $arg), applied: true)],
            'cash_collected' => ['Cash collected', $this->payments($shiftIds, fn ($q) => $q->whereIn('kind', self::BILL_KINDS)->where('method', PaymentMethod::Cash), applied: true)],
            'deposits_taken' => ['Key deposits taken', $this->payments($shiftIds, fn ($q) => $q->where('kind', PaymentKind::DepositIn)->where('deposit_type', DepositType::Key)->where('method', PaymentMethod::Cash))],
            'deposits_returned' => ['Key deposits returned', $this->payments($shiftIds, fn ($q) => $q->where('kind', PaymentKind::DepositRefund)->where('deposit_type', DepositType::Key)->where('method', PaymentMethod::Cash))],
            'guest_deposits' => ['Guest deposits', $this->payments($shiftIds, fn ($q) => $q->whereIn('kind', [PaymentKind::DepositIn, PaymentKind::DepositRefund])->where('deposit_type', DepositType::Guest)->where('method', PaymentMethod::Cash))],
            'other_cash' => ['Other cash', $this->payments($shiftIds, fn ($q) => $q->where('method', PaymentMethod::Cash)->whereNotIn('kind', [...self::BILL_KINDS, PaymentKind::DepositIn, PaymentKind::DepositRefund]))],
            'expenses' => ['Expenses', $this->movements($shiftIds, CashMovementKind::Expense)],
            'remittance' => ['Remittance', $this->movements($shiftIds, CashMovementKind::Remittance)],
            'shortage_payments' => ['Shortage payments', $this->movements($shiftIds, CashMovementKind::ShortagePayment)],
            default => ['', []],
        };

        return ['title' => $result[0], 'rows' => $result[1], 'total' => (int) array_sum(array_column($result[1], 'amount'))];
    }

    private const BILL_KINDS = [PaymentKind::Payment, PaymentKind::Refund, PaymentKind::DepositApplied];

    /**
     * @param  list<int>  $shiftIds
     * @return list<array<string, mixed>>
     */
    private function lines(array $shiftIds, \Closure $filter): array
    {
        return array_values($filter(FolioLine::query()->withoutGlobalScopes()->with(['stay' => fn ($q) => $q->withoutGlobalScopes(), 'room' => fn ($q) => $q->withoutGlobalScopes(), 'creator', 'posOrder' => fn ($q) => $q->withoutGlobalScopes()])
            ->whereIn('shift_session_id', $shiftIds))
            ->orderBy('occurred_at')->orderBy('id')->limit(500)->get()
            ->map(fn (FolioLine $l): array => [
                'id' => $l->id,
                'at' => $l->occurred_at->toIso8601String(),
                'code' => $l->stay->code ?? $l->posOrder->order_no ?? null,
                'room' => $l->room->number ?? null,
                'description' => $l->description.($l->reversal_of_id !== null ? ' (reversal)' : ''),
                'by' => $l->creator->name ?? '',
                'amount' => $l->amount->centavos(),
            ])->all());
    }

    /**
     * @param  list<int>  $shiftIds
     * @return list<array<string, mixed>>
     */
    private function payments(array $shiftIds, \Closure $filter, bool $applied = false): array
    {
        $rows = $filter(Payment::query()->withoutGlobalScopes()->with(['stay' => fn ($q) => $q->withoutGlobalScopes(), 'creator'])->whereIn('shift_session_id', $shiftIds))
            ->orderBy('created_at')->orderBy('id')->limit(500)->get();
        $allocated = $applied
            ? DB::table('payment_allocations')->whereIn('payment_id', $rows->pluck('id'))->groupBy('payment_id')->selectRaw('payment_id, SUM(amount) AS total')->pluck('total', 'payment_id')
            : collect();

        return array_values($rows->map(fn (Payment $p): array => [
            'id' => $p->id,
            'at' => $p->created_at->toIso8601String(),
            'code' => $p->stay->code ?? null,
            'room' => null,
            'description' => str_replace('_', ' ', ucfirst($p->kind->value)).' · '.$p->method->value.($p->deposit_type === null ? '' : ' · '.$p->deposit_type->value.' deposit').($p->note ? " · {$p->note}" : ''),
            'by' => $p->creator->name ?? '',
            'amount' => $applied ? (int) ($allocated[$p->id] ?? 0) : ($p->amount?->centavos() ?? 0),
        ])->all());
    }

    /**
     * @param  list<int>  $shiftIds
     * @return list<array<string, mixed>>
     */
    private function movements(array $shiftIds, CashMovementKind $kind): array
    {
        return array_values(CashMovement::query()->withoutGlobalScopes()->with(['creator', 'expenseCategory' => fn ($q) => $q->withoutGlobalScopes()])
            ->whereIn('shift_session_id', $shiftIds)->where('kind', $kind)->whereNull('voided_at')
            ->orderBy('occurred_at')->orderBy('id')->get()
            ->map(fn (CashMovement $m): array => [
                'id' => $m->id,
                'at' => $m->occurred_at->toIso8601String(),
                'code' => null,
                'room' => null,
                'description' => trim(($m->expenseCategory->name ?? ucfirst(str_replace('_', ' ', $m->kind->value))).($m->note ? " · {$m->note}" : '')),
                'by' => $m->creator->name ?? '',
                'amount' => $m->amount?->centavos() ?? 0,
            ])->all());
    }
}
