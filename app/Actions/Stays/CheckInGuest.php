<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Enums\StayKind;
use App\Enums\StaySource;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Exceptions\RoomNotAvailable;
use App\Exceptions\RoomTaken;
use App\Models\Branch;
use App\Models\Discount;
use App\Models\Room;
use App\Models\Stay;
use App\Models\StayRoom;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\Pricing\DiscountTerms;
use App\Services\Pricing\RateCard;
use App\Services\PricingService;
use App\Services\RoomStatusService;
use App\Services\StayLedger;
use App\Support\Clock;
use App\Support\DuplicateKey;
use App\Support\Money;
use App\Support\Transaction;
use Illuminate\Database\QueryException;

/**
 * Walk-in check-in (screen 6, DATABASE_SCHEMA §8.1, APP_FLOW §4.5).
 *
 * One transaction: lock the open shift, then the room (must be available), price the stay,
 * take the next stay number with the branch row locked, write the stay, stay_rooms, the room
 * and discount lines, the room payment with its allocations, the key deposit, a guest deposit
 * when the change is kept, and move the room to occupied.
 *
 * A second active stay for the room hits stays_one_active_per_room → RoomTaken (53). A
 * repeated idempotency key returns the first stay and writes nothing.
 */
final class CheckInGuest
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CurrentShift $currentShift,
        private readonly PricingService $pricing,
        private readonly RoomStatusService $rooms,
        private readonly StayLedger $ledger,
        private readonly Transaction $transaction,
    ) {}

    /**
     * @param  int  $quantity  hours, nights or days for the stay kind
     * @param  Money|null  $tendered  cash handed over; for GCash and card the amount is the total to collect
     */
    public function handle(
        User $by,
        Room $room,
        string $guestName,
        StayKind $kind,
        int $quantity,
        PaymentMethod $method,
        string $idempotencyKey,
        ?Money $tendered = null,
        ?string $referenceNo = null,
        bool $keepChangeAsDeposit = false,
        ?Discount $discount = null,
        ?string $guestMobile = null,
        ?string $plateNumber = null,
    ): Stay {
        $existing = $this->replay($idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->transaction->run(fn (): Stay => $this->checkIn(
                $by, $room, $guestName, $kind, $quantity, $method, $idempotencyKey, $tendered, $referenceNo,
                $keepChangeAsDeposit, $discount, $guestMobile, $plateNumber,
            ));
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'stays_idempotency_unique')) {
                return $this->replay($idempotencyKey) ?? throw $e;
            }

            throw $e;
        }
    }

    private function checkIn(
        User $by, Room $room, string $guestName, StayKind $kind, int $quantity, PaymentMethod $method, string $idempotencyKey,
        ?Money $tendered, ?string $referenceNo, bool $keepChangeAsDeposit, ?Discount $discount, ?string $guestMobile, ?string $plateNumber,
    ): Stay {
        if ($method === PaymentMethod::Deposit) {
            throw ActionRefused::because('A check-in is paid in cash, GCash or card.');
        }

        // Lock order: shift → room → branch counter (DATABASE_SCHEMA §8).
        $shift = $this->currentShift->forPayment($room->branch_id);
        $locked = Room::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($room->id);

        if ($locked->status !== RoomStatus::Available || ! $locked->is_active) {
            throw RoomNotAvailable::room($locked->number);
        }

        $this->ledger->assertAccepted($locked->branch_id, $method, $referenceNo);
        $branch = Branch::query()->findOrFail($locked->branch_id);
        $now = $this->clock->now();
        $quote = $this->pricing->quoteStay(
            RateCard::forBranch($branch), $locked->room_type_id, $kind, $quantity, $now,
            $discount === null ? null : DiscountTerms::of($discount),
        );

        $total = $quote->totalToCollect();
        $isCash = $method === PaymentMethod::Cash;
        $given = $isCash ? ($tendered ?? throw ActionRefused::because('Type the cash received.')) : $total;

        if ($given->lessThan($total)) {
            throw ActionRefused::notEnoughTendered();
        }

        $change = $given->minus($total);

        $code = $this->nextCode($branch);

        try {
            $stay = Stay::query()->create([
                'branch_id' => $locked->branch_id,
                'code' => $code,
                'state' => StayState::CheckedIn,
                'source' => StaySource::WalkIn,
                'room_id' => $locked->id,
                'room_type_id' => $locked->room_type_id,
                'guest_name' => trim($guestName),
                'guest_mobile' => $guestMobile,
                'plate_number' => $plateNumber,
                'stay_kind' => $kind,
                'hours' => $kind === StayKind::Hourly ? $quantity : null,
                'nights' => $kind === StayKind::Overnight ? $quantity : null,
                'days' => $kind === StayKind::LongStay ? $quantity : null,
                'discount_id' => $discount?->id,
                'quoted_amount' => $quote->netRoomAmount(),
                'checked_in_at' => $now,
                'expected_checkout_at' => $quote->expectedCheckoutAt,
                'cycle_hours_used' => $quote->cycleHoursUsed,
                'checkin_shift_id' => $shift->id,
                'checked_in_by' => $by->id,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'stays_one_active_per_room')) {
                throw RoomTaken::room($locked->number);
            }

            throw $e;
        }

        StayRoom::query()->create(['stay_id' => $stay->id, 'room_id' => $locked->id, 'from_at' => $now, 'created_at' => $now]);

        $roomLine = $this->ledger->line($shift, $stay, $by, LineKind::Room, $quote->description, $quote->roomAmount);
        $discountLine = $quote->discountAmount->isPositive()
            ? $this->ledger->line($shift, $stay, $by, LineKind::Discount, $discount->name ?? 'Discount', $quote->discountAmount->negate())
            : null;

        // The room payment: +room to the room line, −discount to the discount line (TRD §4.9).
        if ($quote->netRoomAmount()->isPositive()) {
            $payment = $this->ledger->payment(
                $shift, $stay, $by, PaymentKind::Payment, $method, $quote->netRoomAmount(),
                tendered: $isCash ? $given : null,
                change: $isCash ? ($keepChangeAsDeposit ? Money::zero() : $change) : null,
                referenceNo: $referenceNo,
            );
            $allocations = [[$roomLine, $quote->roomAmount]];
            if ($discountLine !== null) {
                $allocations[] = [$discountLine, $quote->discountAmount->negate()];
            }
            $this->ledger->allocate($payment, $allocations);
        }

        if ($quote->depositAmount->isPositive()) {
            $this->ledger->payment($shift, $stay, $by, PaymentKind::DepositIn, $method, $quote->depositAmount, DepositType::Key, referenceNo: $referenceNo);
        }

        if ($keepChangeAsDeposit && $isCash && $change->isPositive()) {
            $this->ledger->payment($shift, $stay, $by, PaymentKind::DepositIn, PaymentMethod::Cash, $change, DepositType::Guest, note: 'change kept as deposit');
        }

        $this->rooms->transition($locked, RoomStatus::Occupied, RoomStatusReason::CheckIn, $stay, $by);

        return $stay;
    }

    /** Take the branch's next stay number with its row locked, last in the lock order. */
    private function nextCode(Branch $branch): string
    {
        $locked = Branch::query()->lockForUpdate()->findOrFail($branch->id);
        $number = $locked->next_stay_number;
        $locked->update(['next_stay_number' => $number + 1]);

        return sprintf('%s-%s-%06d', $locked->code_prefix, $this->clock->local()->format('y'), $number);
    }

    private function replay(string $idempotencyKey): ?Stay
    {
        return Stay::query()->withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();
    }
}
