<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Exceptions\ActionRefused;
use App\Models\Discount;
use App\Models\Payment;
use App\Models\Room;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\StayRoom;
use App\Models\User;
use App\Queries\StayQuery;
use App\Services\Pricing\StayQuote;
use App\Support\Clock;
use App\Support\Money;

/**
 * The money and room steps every check-in shares (DATABASE_SCHEMA §8.1 steps 6–9): a walk-in
 * (6), a check-in from a reservation (57 → 6) and a confirmed kiosk hold (7). Runs inside the
 * calling action's transaction, after it has locked the shift, the room and the stay.
 *
 * - stay_rooms from now;
 * - the room line and, with a discount, the discount line;
 * - money paid ahead (the guest deposit held on a reserved stay) applied to the room first,
 *   with a deposit_applied payment (APP_FLOW §4.19);
 * - the rest of the room as one payment, allocated +room to the room line and −discount to the
 *   discount line (TRD §4.9), with tendered and change on a cash payment;
 * - the key deposit_in when the branch takes one; the change as a guest deposit when kept;
 * - the room to occupied.
 *
 * The first payment written carries the form's idempotency key, so a repeated confirm or a
 * repeated check-in from a reservation finds it.
 */
final class CheckInLedger
{
    public function __construct(
        private readonly Clock $clock,
        private readonly StayLedger $ledger,
        private readonly StayQuery $stays,
        private readonly RoomStatusService $rooms,
    ) {}

    /**
     * What the guest pays at the desk for this quote: room − discount − money paid ahead + deposit.
     *
     * @return array{applied: Money, room_due: Money, total: Money}
     */
    public function due(StayQuote $quote, ?Stay $stay = null): array
    {
        $held = $stay === null ? Money::zero() : $this->stays->depositHeld($stay->id, DepositType::Guest);
        $applied = $held->min($quote->netRoomAmount())->max(Money::zero());
        $roomDue = $quote->netRoomAmount()->minus($applied);

        return ['applied' => $applied, 'room_due' => $roomDue, 'total' => $roomDue->plus($quote->depositAmount)];
    }

    /** Refuse before anything is written when the cash does not cover what is due. */
    public function assertTendered(StayQuote $quote, ?Stay $stay, PaymentMethod $method, ?Money $tendered): void
    {
        if ($method === PaymentMethod::Deposit) {
            throw ActionRefused::because('A check-in is paid in cash, GCash or card.');
        }

        $total = $this->due($quote, $stay)['total'];

        if ($method !== PaymentMethod::Cash || ! $total->isPositive()) {
            return;
        }

        if ($tendered === null) {
            throw ActionRefused::because('Type the cash received.');
        }

        if ($tendered->lessThan($total)) {
            throw ActionRefused::notEnoughTendered();
        }
    }

    public function write(
        ShiftSession $shift,
        Stay $stay,
        Room $room,
        User $by,
        StayQuote $quote,
        ?Discount $discount,
        PaymentMethod $method,
        ?Money $tendered,
        ?string $referenceNo,
        bool $keepChangeAsDeposit,
        ?string $idempotencyKey = null,
    ): void {
        $now = $this->clock->now();
        ['applied' => $applied, 'room_due' => $roomDue, 'total' => $total] = $this->due($quote, $stay);
        $isCash = $method === PaymentMethod::Cash;
        $given = $isCash ? ($tendered ?? Money::zero()) : $total;
        $change = $given->minus($total)->max(Money::zero());
        $key = $idempotencyKey;
        $takeKey = static function () use (&$key): ?string {
            $k = $key;
            $key = null;

            return $k;
        };

        StayRoom::query()->create(['stay_id' => $stay->id, 'room_id' => $room->id, 'from_at' => $now, 'created_at' => $now]);

        $roomLine = $this->ledger->line($shift, $stay, $by, LineKind::Room, $quote->description, $quote->roomAmount);
        $discountLine = $quote->discountAmount->isPositive()
            ? $this->ledger->line($shift, $stay, $by, LineKind::Discount, $discount->name ?? 'Discount', $quote->discountAmount->negate())
            : null;

        // Money paid ahead pays the room first; with a discount, the discount line is settled
        // with it, so the room line takes applied + discount (never more than the room amount).
        $discountSettled = false;

        if ($applied->isPositive()) {
            $payment = $this->ledger->payment($shift, $stay, $by, PaymentKind::DepositApplied, PaymentMethod::Deposit, $applied, DepositType::Guest,
                note: 'paid ahead, applied at check-in', idempotencyKey: $takeKey());
            $allocations = [[$roomLine, $applied->plus($quote->discountAmount)]];

            if ($discountLine !== null) {
                $allocations[] = [$discountLine, $quote->discountAmount->negate()];
                $discountSettled = true;
            }

            $this->ledger->allocate($payment, $allocations);
        }

        if ($roomDue->isPositive()) {
            $payment = $this->ledger->payment(
                $shift, $stay, $by, PaymentKind::Payment, $method, $roomDue,
                tendered: $isCash ? $given : null,
                change: $isCash ? ($keepChangeAsDeposit ? Money::zero() : $change) : null,
                referenceNo: $referenceNo,
                idempotencyKey: $takeKey(),
            );
            $onRoom = $discountSettled ? $roomDue : $roomDue->plus($quote->discountAmount);
            $allocations = [[$roomLine, $onRoom]];

            if ($discountLine !== null && ! $discountSettled) {
                $allocations[] = [$discountLine, $quote->discountAmount->negate()];
            }

            $this->ledger->allocate($payment, $allocations);
        }

        if ($quote->depositAmount->isPositive()) {
            $this->ledger->payment($shift, $stay, $by, PaymentKind::DepositIn, $method, $quote->depositAmount, DepositType::Key,
                referenceNo: $referenceNo, idempotencyKey: $takeKey());
        }

        if ($keepChangeAsDeposit && $isCash && $change->isPositive()) {
            $this->ledger->payment($shift, $stay, $by, PaymentKind::DepositIn, PaymentMethod::Cash, $change, DepositType::Guest, note: 'change kept as deposit');
        }

        $this->rooms->transition($room, RoomStatus::Occupied, RoomStatusReason::CheckIn, $stay, $by);
    }

    /** The branch accepts the method, and GCash and card carry a reference number. */
    public function assertAccepted(int $branchId, PaymentMethod $method, ?string $referenceNo): void
    {
        $this->ledger->assertAccepted($branchId, $method, $referenceNo);
    }

    /** The stay whose check-in payment carries this key (a repeated confirm or check-in). */
    public function replay(string $idempotencyKey): ?Stay
    {
        $stayId = Payment::query()->withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->value('stay_id');

        return $stayId === null ? null : Stay::query()->withoutGlobalScopes()->find($stayId);
    }
}
