<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Exceptions\ActionRefused;
use App\Models\Branch;
use App\Models\FolioLine;
use App\Models\HallBooking;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ShiftSession;
use App\Models\User;
use App\Support\Clock;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The money steps a hall booking shares (the stay equivalent is StayLedger). A hall booking is a
 * third ledger owner: every folio_lines/payments row carries hall_booking_id instead of stay_id,
 * the open shift and created_by. Balance and deposit held are derived from the ledgers, never
 * stored. Kept separate from StayLedger so the room money path is untouched.
 */
final class HallLedger
{
    public function __construct(private readonly Clock $clock) {}

    /** One folio_lines row of kind event_hall (amount = quantity × unit, folio_lines_math_chk). */
    public function line(
        ShiftSession $shift,
        HallBooking $booking,
        User $by,
        string $description,
        Money $unit,
        int $quantity = 1,
        LineKind $kind = LineKind::EventHall,
        ?FolioLine $reversalOf = null,
        ?string $reversalReason = null,
        ?int $approvalId = null,
    ): FolioLine {
        $now = $this->clock->now();

        return FolioLine::query()->create([
            'branch_id' => $booking->branch_id,
            'shift_session_id' => $shift->id,
            'hall_booking_id' => $booking->id,
            'kind' => $kind,
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
        HallBooking $booking,
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
            'branch_id' => $booking->branch_id,
            'shift_session_id' => $shift->id,
            'hall_booking_id' => $booking->id,
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

    public function balanceDue(int $bookingId): Money
    {
        $lines = (int) DB::table('folio_lines')->where('hall_booking_id', $bookingId)->sum('amount');
        $paid = (int) DB::table('payment_allocations')
            ->join('folio_lines', 'folio_lines.id', '=', 'payment_allocations.folio_line_id')
            ->where('folio_lines.hall_booking_id', $bookingId)
            ->sum('payment_allocations.amount');

        return Money::ofCentavos($lines - $paid);
    }

    /** The guest deposit held on a booking: deposits in, less what was refunded or applied. */
    public function depositHeld(int $bookingId): Money
    {
        $held = DB::table('payments')
            ->where('hall_booking_id', $bookingId)
            ->where('deposit_type', DepositType::Guest->value)
            ->selectRaw('COALESCE(SUM(CASE WHEN kind = ? THEN -amount ELSE amount END), 0) AS held', [PaymentKind::DepositApplied->value])
            ->value('held');

        return Money::ofCentavos((int) $held);
    }

    /**
     * Apply the held guest deposit to the open balance (deposit_applied), oldest line first.
     * Used at completion before collecting the cash balance.
     */
    public function applyGuestDeposit(ShiftSession $shift, HallBooking $booking, User $by): ?Payment
    {
        $amount = $this->depositHeld($booking->id)->min($this->balanceDue($booking->id));

        if (! $amount->isPositive()) {
            return null;
        }

        $payment = $this->payment($shift, $booking, $by, PaymentKind::DepositApplied, PaymentMethod::Deposit, $amount, DepositType::Guest);
        $this->allocate($payment, $this->spread($booking->id, $amount));

        return $payment;
    }

    /** Take a cash/GCash/card payment against the balance, oldest line first; change or kept as deposit. */
    public function collect(
        ShiftSession $shift,
        HallBooking $booking,
        User $by,
        PaymentMethod $method,
        Money $given,
        ?string $referenceNo = null,
        bool $keepChangeAsDeposit = false,
        ?string $idempotencyKey = null,
    ): ?Payment {
        if (! $given->isPositive()) {
            throw ActionRefused::because('Type the amount received.');
        }

        $this->assertAccepted($booking->branch_id, $method, $referenceNo);
        $balance = $this->balanceDue($booking->id);

        if (! $balance->isPositive()) {
            return null;
        }

        $apply = $given->min($balance);
        $excess = $given->minus($apply);

        if ($excess->isPositive() && $method !== PaymentMethod::Cash && ! $keepChangeAsDeposit) {
            throw ActionRefused::because('A '.$method->label().' payment cannot be more than the balance due.');
        }

        $isCash = $method === PaymentMethod::Cash;
        $payment = $this->payment(
            $shift, $booking, $by, PaymentKind::Payment, $method, $apply,
            tendered: $isCash ? $given : null,
            change: $isCash ? ($keepChangeAsDeposit ? Money::zero() : $excess) : null,
            referenceNo: $referenceNo,
            idempotencyKey: $idempotencyKey,
        );
        $this->allocate($payment, $this->spread($booking->id, $apply));

        if ($excess->isPositive() && $keepChangeAsDeposit) {
            $this->payment($shift, $booking, $by, PaymentKind::DepositIn, $method, $excess, DepositType::Guest, referenceNo: $referenceNo, note: 'change kept as deposit');
        }

        return $payment;
    }

    /** Refund the held guest deposit in cash, or keep it as income with a forfeited_deposit line. */
    public function settleGuestDeposit(ShiftSession $shift, HallBooking $booking, User $by, bool $refund, string $note, ?int $approvalId = null): ?Money
    {
        $held = $this->depositHeld($booking->id);

        if (! $held->isPositive()) {
            return null;
        }

        if ($refund) {
            $this->payment($shift, $booking, $by, PaymentKind::DepositRefund, PaymentMethod::Cash, $held->negate(), DepositType::Guest, note: $note, approvalId: $approvalId);

            return $held;
        }

        $line = $this->line($shift, $booking, $by, 'Deposit forfeited', $held, kind: LineKind::ForfeitedDeposit, approvalId: $approvalId);
        $applied = $this->payment($shift, $booking, $by, PaymentKind::DepositApplied, PaymentMethod::Deposit, $held, DepositType::Guest, note: $note, approvalId: $approvalId);
        $this->allocate($applied, [[$line, $held]]);

        return $held;
    }

    public function assertAccepted(int $branchId, PaymentMethod $method, ?string $referenceNo): void
    {
        if ($method === PaymentMethod::Deposit) {
            return;
        }

        $branch = Branch::query()->findOrFail($branchId);

        if (! $branch->payment_methods->contains($method)) {
            throw ActionRefused::methodNotAccepted($method->label());
        }

        if (in_array($method, [PaymentMethod::Gcash, PaymentMethod::Card], true) && ($referenceNo === null || trim($referenceNo) === '')) {
            throw ActionRefused::referenceRequired($method->label());
        }
    }

    /**
     * @param  list<int>|null  $onlyLineIds
     * @return list<array{0: FolioLine, 1: Money}>
     */
    private function spread(int $bookingId, Money $amount, ?array $onlyLineIds = null): array
    {
        $open = array_values(array_filter(
            $this->openLines($bookingId),
            static fn (array $row): bool => $onlyLineIds === null || in_array($row['line']->id, $onlyLineIds, true),
        ));

        $allocations = [];
        $budget = $amount;

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

    /** @return list<array{line: FolioLine, due: Money}> */
    private function openLines(int $bookingId): array
    {
        $lines = FolioLine::query()->withoutGlobalScopes()
            ->where('hall_booking_id', $bookingId)
            ->withSum('allocations', 'amount')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $open = [];

        foreach ($lines as $line) {
            $due = ($line->amount ?? Money::zero())->minus(Money::ofCentavos((int) $line->getAttribute('allocations_sum_amount')));

            if (! $due->isZero()) {
                $open[] = ['line' => $line, 'due' => $due];
            }
        }

        return $open;
    }
}
