<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\StockMovementKind;
use App\Exceptions\ActionRefused;
use App\Exceptions\InsufficientStock;
use App\Models\Branch;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PosOrder;
use App\Models\Product;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\User;
use App\Queries\StayQuery;
use App\Support\Clock;
use App\Support\Money;

/**
 * The steps that several stay actions share (TRD §3.2: shared steps live in services). Every
 * method runs inside the calling action's transaction, after it has locked the shift, the
 * rooms, the stay and the products in the order of DATABASE_SCHEMA §8.
 *
 * Every row written carries the open shift and created_by = the person signed on (CLAUDE.md
 * rule 4), and occurred_at from Clock.
 */
final class StayLedger
{
    public function __construct(
        private readonly Clock $clock,
        private readonly StayQuery $stays,
        private readonly StockBook $stock,
    ) {}

    /** One folio_lines row: amount = quantity × unit_amount (folio_lines_math_chk). */
    public function line(
        ShiftSession $shift,
        Stay $stay,
        User $by,
        LineKind $kind,
        string $description,
        Money $unit,
        int $quantity = 1,
        ?Product $product = null,
        ?PosOrder $order = null,
        ?FolioLine $reversalOf = null,
        ?string $reversalReason = null,
        ?int $approvalId = null,
    ): FolioLine {
        $now = $this->clock->now();

        return FolioLine::query()->create([
            'branch_id' => $stay->branch_id,
            'shift_session_id' => $shift->id,
            'stay_id' => $stay->id,
            'pos_order_id' => $order !== null ? $order->id : $reversalOf?->pos_order_id,
            'room_id' => $reversalOf !== null ? $reversalOf->room_id : $stay->room_id,
            'kind' => $kind,
            'product_id' => $product?->id,
            'description' => mb_substr($description, 0, 120),
            'quantity' => $quantity,
            'unit_amount' => $unit,
            'amount' => $unit->times($quantity),
            'reversal_of_id' => $reversalOf?->id,
            'reversal_reason' => $reversalReason === null ? null : mb_substr($reversalReason, 0, 255),
            'approval_id' => $approvalId,
            'created_by' => $by->id,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    public function payment(
        ShiftSession $shift,
        Stay $stay,
        User $by,
        PaymentKind $kind,
        PaymentMethod $method,
        Money $amount,
        ?DepositType $depositType = null,
        ?Money $tendered = null,
        ?Money $change = null,
        ?string $referenceNo = null,
        ?string $note = null,
        ?int $approvalId = null,
        ?string $idempotencyKey = null,
    ): Payment {
        $now = $this->clock->now();

        return Payment::query()->create([
            'branch_id' => $stay->branch_id,
            'shift_session_id' => $shift->id,
            'stay_id' => $stay->id,
            'kind' => $kind,
            'method' => $method,
            'deposit_type' => $depositType,
            'amount' => $amount,
            'tendered_amount' => $tendered,
            'change_amount' => $change,
            'reference_no' => $referenceNo,
            'approval_id' => $approvalId,
            'note' => $note,
            'idempotency_key' => $idempotencyKey,
            'created_by' => $by->id,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    /** @param  list<array{0: FolioLine, 1: Money}>  $allocations */
    public function allocate(Payment $payment, array $allocations): void
    {
        $now = $this->clock->now();

        foreach ($allocations as [$line, $amount]) {
            if ($amount->isZero()) {
                continue;
            }

            PaymentAllocation::query()->create([
                'payment_id' => $payment->id,
                'folio_line_id' => $line->id,
                'amount' => $amount,
                'created_at' => $now,
            ]);
        }
    }

    /**
     * Take a payment (DATABASE_SCHEMA §8.4). Applies the smaller of the amount given and the
     * balance due, oldest unpaid line first. Cash above the balance is change, or a guest
     * deposit when the guest keeps it. Method `deposit` pays from the guest deposit and is
     * refused above what is held. Returns null when nothing is due.
     */
    public function collect(
        ShiftSession $shift,
        Stay $stay,
        User $by,
        PaymentMethod $method,
        Money $given,
        ?string $referenceNo = null,
        bool $keepChangeAsDeposit = false,
        ?DepositType $fromDeposit = null,
        ?string $idempotencyKey = null,
    ): ?Payment {
        if (! $given->isPositive()) {
            throw ActionRefused::because('Type the amount received.');
        }

        $this->assertAccepted($stay, $method, $referenceNo);

        $balance = $this->stays->balanceDue($stay->id);

        if (! $balance->isPositive()) {
            return null;
        }

        $apply = $given->min($balance);
        $excess = $given->minus($apply);

        if ($method === PaymentMethod::Deposit) {
            if (($fromDeposit ?? DepositType::Guest) === DepositType::Key) {
                throw ActionRefused::keyDepositLocked();
            }

            if ($given->greaterThan($this->stays->depositHeld($stay->id, DepositType::Guest))) {
                throw ActionRefused::notEnoughDeposit();
            }

            $payment = $this->payment($shift, $stay, $by, PaymentKind::DepositApplied, PaymentMethod::Deposit, $apply, DepositType::Guest, idempotencyKey: $idempotencyKey);
            $this->allocate($payment, $this->spread($stay->id, $apply));

            return $payment;
        }

        if ($excess->isPositive() && $method !== PaymentMethod::Cash && ! $keepChangeAsDeposit) {
            throw ActionRefused::because('A '.$method->label().' payment cannot be more than the balance due.');
        }

        $isCash = $method === PaymentMethod::Cash;
        $payment = $this->payment(
            $shift, $stay, $by, PaymentKind::Payment, $method, $apply,
            tendered: $isCash ? $given : null,
            change: $isCash ? ($keepChangeAsDeposit ? Money::zero() : $excess) : null,
            referenceNo: $referenceNo,
            idempotencyKey: $idempotencyKey,
        );
        $this->allocate($payment, $this->spread($stay->id, $apply));

        if ($excess->isPositive() && $keepChangeAsDeposit) {
            $this->payment($shift, $stay, $by, PaymentKind::DepositIn, $method, $excess, DepositType::Guest, referenceNo: $referenceNo, note: 'change kept as deposit');
        }

        return $payment;
    }

    /**
     * Pay some lines from a deposit held (check-out, DATABASE_SCHEMA §8.8 step 4). Applies up to
     * what is held. Returns null when nothing is due on those lines or nothing is held.
     *
     * @param  list<int>  $lineIds
     */
    public function applyDeposit(ShiftSession $shift, Stay $stay, User $by, DepositType $type, array $lineIds): ?Payment
    {
        $due = Money::zero();
        foreach ($this->stays->openLines($stay->id) as $row) {
            if (in_array($row['line']->id, $lineIds, true)) {
                $due = $due->plus($row['due']);
            }
        }

        $amount = $this->stays->depositHeld($stay->id, $type)->min($due);

        if (! $amount->isPositive()) {
            return null;
        }

        $payment = $this->payment($shift, $stay, $by, PaymentKind::DepositApplied, PaymentMethod::Deposit, $amount, $type);
        $this->allocate($payment, $this->spread($stay->id, $amount, $lineIds));

        return $payment;
    }

    /** Hand back what is held of a deposit type, in cash (deposit_refund). */
    public function refundDeposit(ShiftSession $shift, Stay $stay, User $by, DepositType $type, ?string $note = null, ?int $approvalId = null): ?Payment
    {
        $held = $this->stays->depositHeld($stay->id, $type);

        if (! $held->isPositive()) {
            return null;
        }

        return $this->payment($shift, $stay, $by, PaymentKind::DepositRefund, PaymentMethod::Cash, $held->negate(), $type, note: $note, approvalId: $approvalId);
    }

    /**
     * Reverse lines (DATABASE_SCHEMA §8.9 step 2): for each, a reversing line with the quantity
     * negated, stamped with the open shift; one cash refund whose negative allocations settle
     * what had been paid on each line; stock back for stock-tracked products, locked by id.
     * Returns the refund, or null when nothing had been paid.
     *
     * @param  list<FolioLine>  $lines  not reversals and not reversed yet
     */
    public function reverse(ShiftSession $shift, Stay $stay, User $by, array $lines, string $reason, ?int $approvalId = null): ?Payment
    {
        usort($lines, static fn (FolioLine $a, FolioLine $b): int => $a->id <=> $b->id);

        $productIds = array_values(array_unique(array_filter(array_map(static fn (FolioLine $l): ?int => $l->product_id, $lines))));
        sort($productIds);
        $products = $productIds === [] ? collect() : Product::query()->withoutGlobalScopes()
            ->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $paidOn = [];
        foreach (PaymentAllocation::query()->whereIn('folio_line_id', array_map(static fn (FolioLine $l): int => $l->id, $lines))->get() as $allocation) {
            $paidOn[$allocation->folio_line_id] = ($paidOn[$allocation->folio_line_id] ?? Money::zero())->plus($allocation->amount ?? Money::zero());
        }

        $refunds = [];
        $refundTotal = Money::zero();

        foreach ($lines as $line) {
            $product = $line->product_id === null ? null : $products->get($line->product_id);
            $reversal = $this->line(
                $shift, $stay, $by, $line->kind, 'Void: '.$line->description, $line->unit_amount ?? Money::zero(), -$line->quantity,
                $product, reversalOf: $line, reversalReason: $reason, approvalId: $approvalId,
            );

            $paid = $paidOn[$line->id] ?? Money::zero();

            if (! $paid->isZero()) {
                $refunds[] = [$reversal, $paid->negate()];
                $refundTotal = $refundTotal->plus($paid);
            }

            if ($product !== null) {
                $this->stockBack($shift, $product, $line->quantity, $reversal, $by);
            }
        }

        if (! $refundTotal->isPositive()) {
            return null;
        }

        $refund = $this->payment($shift, $stay, $by, PaymentKind::Refund, PaymentMethod::Cash, $refundTotal->negate(), note: mb_substr($reason, 0, 255), approvalId: $approvalId);
        $this->allocate($refund, $refunds);

        return $refund;
    }

    /** Stock out for a sold line, on a product the caller has locked (stock_movements kind sale). */
    public function stockOut(ShiftSession $shift, Product $product, int $quantity, FolioLine $line, User $by): void
    {
        if (! $product->tracks_stock) {
            return;
        }

        if ($product->stock_on_hand < $quantity) {
            throw InsufficientStock::product($product->name, $product->stock_on_hand);
        }

        $this->moveStock($shift, $product, StockMovementKind::Sale, -$quantity, $line, $by);
    }

    /** Stock back for a reversed line (stock_movements kind sale_reversal). */
    public function stockBack(ShiftSession $shift, Product $product, int $quantity, FolioLine $reversal, User $by): void
    {
        if ($product->tracks_stock && $quantity > 0) {
            $this->moveStock($shift, $product, StockMovementKind::SaleReversal, $quantity, $reversal, $by);
        }
    }

    /**
     * Spread an amount over the stay's open lines (DATABASE_SCHEMA §8.4 step 4): credits such
     * as an unpaid discount line are settled with it, then the rest goes to the oldest unpaid
     * line first, so a smaller payment leaves the newest lines unpaid.
     *
     * @param  list<int>|null  $onlyLineIds
     * @return list<array{0: FolioLine, 1: Money}>
     */
    private function spread(int $stayId, Money $amount, ?array $onlyLineIds = null): array
    {
        $open = array_values(array_filter(
            $this->stays->openLines($stayId),
            static fn (array $row): bool => $onlyLineIds === null || in_array($row['line']->id, $onlyLineIds, true),
        ));

        $allocations = [];
        $budget = $amount;

        foreach ($open as $row) {
            if ($row['due']->isNegative()) {
                $allocations[] = [$row['line'], $row['due']];
                $budget = $budget->minus($row['due']);
            }
        }

        foreach ($open as $row) {
            if (! $row['due']->isPositive() || ! $budget->isPositive()) {
                continue;
            }

            $take = $row['due']->min($budget);
            $allocations[] = [$row['line'], $take];
            $budget = $budget->minus($take);
        }

        return $allocations;
    }

    /** The branch accepts the method, and GCash and card carry a reference number (TRD §6). */
    public function assertAccepted(Stay|int $stayOrBranch, PaymentMethod $method, ?string $referenceNo): void
    {
        if ($method === PaymentMethod::Deposit) {
            return;
        }

        $branch = Branch::query()->findOrFail(is_int($stayOrBranch) ? $stayOrBranch : $stayOrBranch->branch_id);

        if (! $branch->payment_methods->contains($method)) {
            throw ActionRefused::methodNotAccepted($method->label());
        }

        if (in_array($method, [PaymentMethod::Gcash, PaymentMethod::Card], true) && ($referenceNo === null || trim($referenceNo) === '')) {
            throw ActionRefused::referenceRequired($method->label());
        }
    }

    private function moveStock(ShiftSession $shift, Product $product, StockMovementKind $kind, int $quantity, FolioLine $line, User $by): void
    {
        $this->stock->move($shift, $product, $kind, $quantity, $by, $line);
    }
}
