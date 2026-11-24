<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\RoomStatus;
use App\Enums\StayKind;
use App\Enums\StaySource;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Exceptions\NotAvailable;
use App\Exceptions\RoomNotAvailable;
use App\Exceptions\RoomTaken;
use App\Models\Branch;
use App\Models\Discount;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Queries\RoomQuery;
use App\Services\CheckInLedger;
use App\Services\CurrentShift;
use App\Services\HoldExpiry;
use App\Services\Pricing\DiscountTerms;
use App\Services\Pricing\RateCard;
use App\Services\PricingService;
use App\Services\StayNumber;
use App\Support\Clock;
use App\Support\DuplicateKey;
use App\Support\Money;
use App\Support\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Check in a guest (screen 6, DATABASE_SCHEMA §8.1, APP_FLOW §4.5 and §4.19).
 *
 * A walk-in: one transaction locks the open shift, then the room (must be available), prices
 * the stay, takes the next stay number with the branch row locked, writes the stay and hands the
 * lines, payments, deposits and the room move to CheckInLedger. A walk-in whose stay would run
 * into a reservation of the room is refused (NotAvailable).
 *
 * From a reservation (57 → 6): the reserved stay (or a no-show whose money paid ahead was not
 * settled) is locked after the room and becomes checked_in; its stay option comes from the
 * reservation; money paid ahead is applied to the room with deposit_applied and only the rest
 * is collected. For an "any room" reservation the desk picks a free room of the type.
 *
 * A second active stay for the room hits stays_one_active_per_room → RoomTaken (53). A repeated
 * idempotency key returns the first stay and writes nothing.
 */
final class CheckInGuest
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CurrentShift $currentShift,
        private readonly PricingService $pricing,
        private readonly CheckInLedger $checkIn,
        private readonly RoomQuery $rooms,
        private readonly HoldExpiry $holds,
        private readonly StayNumber $numbers,
        private readonly Transaction $transaction,
    ) {}

    /**
     * @param  int  $quantity  hours, nights or days for the stay kind (ignored from a reservation)
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
        ?Stay $reservation = null,
    ): Stay {
        $existing = $this->replay($idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->transaction->run(fn (): Stay => $reservation === null
                ? $this->walkIn($by, $room, $guestName, $kind, $quantity, $method, $idempotencyKey, $tendered, $referenceNo, $keepChangeAsDeposit, $discount, $guestMobile, $plateNumber)
                : $this->fromReservation($by, $room, $reservation, $guestName, $method, $idempotencyKey, $tendered, $referenceNo, $keepChangeAsDeposit, $discount, $guestMobile, $plateNumber));
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'stays_idempotency_unique') || DuplicateKey::on($e, 'payments_idempotency_unique')) {
                return $this->replay($idempotencyKey) ?? throw $e;
            }

            throw $e;
        }
    }

    private function walkIn(
        User $by, Room $room, string $guestName, StayKind $kind, int $quantity, PaymentMethod $method, string $idempotencyKey,
        ?Money $tendered, ?string $referenceNo, bool $keepChangeAsDeposit, ?Discount $discount, ?string $guestMobile, ?string $plateNumber,
    ): Stay {
        if ($method === PaymentMethod::Deposit) {
            throw ActionRefused::because('A check-in is paid in cash, GCash or card.');
        }

        // Lock order: shift → room → branch counter (DATABASE_SCHEMA §8).
        $shift = $this->currentShift->forPayment($room->branch_id);
        $locked = $this->lockAvailableRoom($room);

        $branch = Branch::query()->findOrFail($locked->branch_id);
        $now = $this->clock->now();
        $quote = $this->pricing->quoteStay(
            RateCard::forBranch($branch), $locked->room_type_id, $kind, $quantity, $now,
            $discount === null ? null : DiscountTerms::of($discount),
            roomId: $locked->id, floorId: $locked->floor_id,
        );

        $this->assertNotReserved($locked, $quote->expectedCheckoutAt);
        $this->checkIn->assertTendered($quote, null, $method, $tendered);
        $this->checkIn->assertAccepted($locked->branch_id, $method, $referenceNo);

        $code = $this->numbers->next($branch);

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
                throw RoomTaken::room($locked->number, $locked->id);
            }

            throw $e;
        }

        $this->checkIn->write($shift, $stay, $locked, $by, $quote, $discount, $method, $tendered, $referenceNo, $keepChangeAsDeposit);

        return $stay;
    }

    private function fromReservation(
        User $by, Room $room, Stay $reservation, string $guestName, PaymentMethod $method, string $idempotencyKey,
        ?Money $tendered, ?string $referenceNo, bool $keepChangeAsDeposit, ?Discount $discount, ?string $guestMobile, ?string $plateNumber,
    ): Stay {
        // Lock order: shift → room → stay → branch (DATABASE_SCHEMA §8).
        $shift = $this->currentShift->forPayment($room->branch_id);
        $locked = $this->lockAvailableRoom($room);
        $stay = Stay::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($reservation->id);

        if ($stay->state === StayState::CheckedIn) {
            return $stay;
        }

        $this->assertCanCheckIn($stay, $locked);

        $branch = Branch::query()->findOrFail($locked->branch_id);
        $now = $this->clock->now();
        $quote = $this->pricing->quoteStay(
            RateCard::forBranch($branch), $stay->room_type_id, $stay->stay_kind, (int) ($stay->hours ?? $stay->nights ?? $stay->days ?? 1), $now,
            $discount === null ? null : DiscountTerms::of($discount),
            roomId: $locked->id, floorId: $locked->floor_id,
        );

        $this->assertNotReserved($locked, $quote->expectedCheckoutAt, $stay->id);
        $this->checkIn->assertTendered($quote, $stay, $method, $tendered);
        $this->checkIn->assertAccepted($locked->branch_id, $method, $referenceNo);

        try {
            $stay->update([
                'state' => StayState::CheckedIn,
                'room_id' => $locked->id,
                'guest_name' => trim($guestName) === '' ? $stay->guest_name : trim($guestName),
                'guest_mobile' => $guestMobile ?? $stay->guest_mobile,
                'plate_number' => $plateNumber,
                'discount_id' => $discount?->id,
                'quoted_amount' => $quote->netRoomAmount(),
                'checked_in_at' => $now,
                'expected_checkout_at' => $quote->expectedCheckoutAt,
                'cycle_hours_used' => $quote->cycleHoursUsed,
                'checkin_shift_id' => $shift->id,
                'checked_in_by' => $by->id,
                'closed_reason' => null,
            ]);
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'stays_one_active_per_room')) {
                throw RoomTaken::room($locked->number, $locked->id);
            }

            throw $e;
        }

        $this->checkIn->write($shift, $stay, $locked, $by, $quote, $discount, $method, $tendered, $referenceNo, $keepChangeAsDeposit, $idempotencyKey);

        return $stay;
    }

    /** Lock the room; it must be available and active. A hold on it that ran out is expired first. */
    private function lockAvailableRoom(Room $room): Room
    {
        $locked = Room::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($room->id);

        if ($locked->status !== RoomStatus::Available || ! $locked->is_active) {
            throw RoomNotAvailable::room($locked->number, $locked->id);
        }

        $this->holds->expireDue($locked->branch_id, $locked->id);

        return $locked;
    }

    /**
     * A reserved stay (or a no-show whose money paid ahead was not settled) of this branch, for
     * this room or, for "any room", a room of its type (APP_FLOW §5.1, TRD §4.9).
     */
    private function assertCanCheckIn(Stay $stay, Room $room): void
    {
        if (! in_array($stay->state, [StayState::Reserved, StayState::NoShow], true) || $stay->branch_id !== $room->branch_id) {
            throw ActionRefused::because("{$stay->code} is not a reservation that can be checked in.");
        }

        if ($stay->state === StayState::NoShow && $this->settled($stay)) {
            throw ActionRefused::because("The money paid ahead on {$stay->code} was already settled. Check the guest in as a walk-in.");
        }

        if ($stay->room_id !== null && $stay->room_id !== $room->id) {
            throw ActionRefused::because("{$stay->code} is for another room.");
        }

        if ($stay->room_type_id !== $room->room_type_id) {
            throw ActionRefused::because("{$stay->code} is for another room type.");
        }
    }

    /** A no-show whose money paid ahead was refunded or kept (RefundNoShow). */
    private function settled(Stay $stay): bool
    {
        return Payment::query()->withoutGlobalScopes()->where('stay_id', $stay->id)->where('kind', PaymentKind::DepositRefund)->exists()
            || FolioLine::query()->withoutGlobalScopes()->where('stay_id', $stay->id)->where('kind', LineKind::ForfeitedDeposit)->exists();
    }

    /** A reservation of this room that starts before the new stay would end blocks it. */
    private function assertNotReserved(Room $room, CarbonImmutable $until, ?int $exceptStayId = null): void
    {
        $now = $this->clock->now();
        $reserved = $this->rooms->reservationOverlapping($room->id, $now, $until->greaterThan($now) ? $until : $now->addMinute(), $exceptStayId);

        if ($reserved !== null && $reserved->arrival_at !== null) {
            throw NotAvailable::reserved($room->number, $this->clock->toLocal($reserved->arrival_at)->format('D j M, g:i A'));
        }
    }

    private function replay(string $idempotencyKey): ?Stay
    {
        return Stay::query()->withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first()
            ?? $this->checkIn->replay($idempotencyKey);
    }
}
