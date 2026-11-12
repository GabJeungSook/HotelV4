<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Enums\DepositType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\StayKind;
use App\Enums\StaySource;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Exceptions\NotAvailable;
use App\Models\Branch;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Queries\RoomQuery;
use App\Services\CurrentShift;
use App\Services\Pricing\RateCard;
use App\Services\PricingService;
use App\Services\StayLedger;
use App\Services\StayNumber;
use App\Support\Clock;
use App\Support\DuplicateKey;
use App\Support\Money;
use App\Support\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Save a reservation (58, DATABASE_SCHEMA §8.7, APP_FLOW §4.19).
 *
 * One transaction. With money paid ahead the open shift is locked first (it carries the
 * payment). Then the room is locked, or for "any room" every room of the type by id; the stay is
 * priced by PricingService for its arrival; the room must be free for [arrival_at,
 * expected_checkout_at) — no open stay running into it and no other reservation overlapping it
 * — or, for "any room", the type must still have a room left after the other "any" reservations
 * of the period. NotAvailable otherwise. The stay is written `reserved` with its code (branch
 * row locked last), and the money paid ahead as a guest deposit_in on the open shift.
 *
 * A repeated key returns the first reservation.
 */
final class ReserveRoom
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CurrentShift $currentShift,
        private readonly PricingService $pricing,
        private readonly RoomQuery $rooms,
        private readonly StayLedger $ledger,
        private readonly StayNumber $numbers,
        private readonly Transaction $transaction,
    ) {}

    /** @param  int  $quantity  hours, nights or days for the stay kind */
    public function handle(
        User $by,
        string $guestName,
        ?string $guestMobile,
        RoomType $type,
        ?Room $room,
        StayKind $kind,
        int $quantity,
        CarbonImmutable $arrivalAt,
        string $idempotencyKey,
        ?PaymentMethod $method = null,
        ?Money $paidAhead = null,
        ?string $referenceNo = null,
    ): Stay {
        $existing = Stay::query()->withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        $name = trim((string) preg_replace('/\s+/', ' ', $guestName));

        if (preg_match_all('/\p{L}/u', $name) < 3) {
            throw ActionRefused::because('Type the guest’s full name.');
        }

        if ($arrivalAt->lessThan($this->clock->now()->subMinutes(5))) {
            throw ActionRefused::because('The arrival time has already passed. Pick a later time.');
        }

        if ($room !== null && $room->room_type_id !== $type->id) {
            throw ActionRefused::because("Room {$room->number} is not a {$type->name} room.");
        }

        $paying = $paidAhead !== null && $paidAhead->isPositive();

        if ($paying && ($method === null || $method === PaymentMethod::Deposit)) {
            throw ActionRefused::because('Pick how the money paid ahead was paid.');
        }

        try {
            return $this->transaction->run(fn (): Stay => $this->reserve($by, $name, $guestMobile, $type, $room, $kind, $quantity, $arrivalAt, $idempotencyKey, $paying ? $method : null, $paying ? $paidAhead : null, $referenceNo));
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'stays_idempotency_unique')) {
                return Stay::query()->withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first() ?? throw $e;
            }

            throw $e;
        }
    }

    private function reserve(
        User $by, string $name, ?string $mobile, RoomType $type, ?Room $room, StayKind $kind, int $quantity, CarbonImmutable $arrivalAt,
        string $idempotencyKey, ?PaymentMethod $method, ?Money $paidAhead, ?string $referenceNo,
    ): Stay {
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($type->branch_id);

        if (! $branch->reservations_enabled) {
            throw ActionRefused::because('This branch does not take reservations.');
        }

        // Lock order: shift → room(s) by id → branch counter (DATABASE_SCHEMA §8).
        $shift = $paidAhead === null ? null : $this->currentShift->forPayment($branch->id);

        if ($method !== null) {
            $this->ledger->assertAccepted($branch->id, $method, $referenceNo);
        }

        $quote = $this->pricing->quoteStay(RateCard::forBranch($branch), $type->id, $kind, $quantity, $arrivalAt);
        $until = $quote->expectedCheckoutAt;

        if ($room !== null) {
            $locked = Room::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($room->id);

            if (! $this->rooms->reservableFor($locked, $arrivalAt, $until)) {
                throw NotAvailable::room($locked->number);
            }
        } else {
            Room::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('room_type_id', $type->id)->orderBy('id')->lockForUpdate()->get(['id']);
            $free = $this->rooms->freeForType($branch->id, $type->id, $arrivalAt, $until)->count() - $this->rooms->anyReservedCount($branch->id, $type->id, $arrivalAt, $until);

            if ($free <= 0) {
                throw NotAvailable::type($type->name, $this->clock->toLocal($arrivalAt)->format('D j M'));
            }
        }

        $stay = Stay::query()->create([
            'branch_id' => $branch->id,
            'code' => $this->numbers->next($branch),
            'state' => StayState::Reserved,
            'source' => StaySource::Reservation,
            'room_id' => $room?->id,
            'room_type_id' => $type->id,
            'guest_name' => $name,
            'guest_mobile' => $mobile,
            'stay_kind' => $kind,
            'hours' => $kind === StayKind::Hourly ? $quantity : null,
            'nights' => $kind === StayKind::Overnight ? $quantity : null,
            'days' => $kind === StayKind::LongStay ? $quantity : null,
            'quoted_amount' => $quote->netRoomAmount(),
            'arrival_at' => $arrivalAt,
            'expected_checkout_at' => $until,
            'idempotency_key' => $idempotencyKey,
        ]);

        if ($shift !== null && $method !== null) {
            $this->ledger->payment($shift, $stay, $by, PaymentKind::DepositIn, $method, $paidAhead, DepositType::Guest, referenceNo: $referenceNo, note: 'paid ahead');
        }

        return $stay;
    }
}
