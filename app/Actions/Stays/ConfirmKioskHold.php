<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Enums\PaymentMethod;
use App\Enums\RoomStatus;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Exceptions\HoldExpired;
use App\Exceptions\RoomNotAvailable;
use App\Models\Branch;
use App\Models\Discount;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\CheckInLedger;
use App\Services\CurrentShift;
use App\Services\HoldExpiry;
use App\Services\Pricing\DiscountTerms;
use App\Services\Pricing\RateCard;
use App\Services\PricingService;
use App\Support\Clock;
use App\Support\DuplicateKey;
use App\Support\Money;
use App\Support\Transaction;
use Illuminate\Database\QueryException;

/**
 * Take the money for a kiosk hold (screen 7, DATABASE_SCHEMA §8.2, APP_FLOW §4.6 steps 4–8).
 *
 * As a walk-in check-in, except the stay already exists: lock the open shift, the room and the
 * stay; refuse with HoldExpired unless the stay is still held and hold_expires_at is in the
 * future; then the stay becomes checked_in with checked_in_at = now and the expected check-out
 * worked out again from now (the hours start at the desk, not at the kiosk). Room, hours and
 * discount are the ones the guest chose. Lines, payments, deposits and the room move are
 * CheckInLedger's.
 *
 * A hold that has run out is set to expired at once, after the refusal has rolled back, so the
 * room is back on sale (APP_FLOW §6 case 7). A repeated key returns the checked-in stay.
 */
final class ConfirmKioskHold
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CurrentShift $currentShift,
        private readonly PricingService $pricing,
        private readonly CheckInLedger $checkIn,
        private readonly HoldExpiry $holds,
        private readonly Transaction $transaction,
    ) {}

    public function handle(
        User $by,
        Stay $stay,
        PaymentMethod $method,
        string $idempotencyKey,
        ?Money $tendered = null,
        ?string $referenceNo = null,
        bool $keepChangeAsDeposit = false,
    ): Stay {
        $existing = $this->checkIn->replay($idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->transaction->run(fn (): Stay => $this->confirm($by, $stay, $method, $idempotencyKey, $tendered, $referenceNo, $keepChangeAsDeposit));
        } catch (HoldExpired $e) {
            $this->holds->expireDue($stay->branch_id, $stay->room_id);

            throw $e;
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'payments_idempotency_unique')) {
                return $this->checkIn->replay($idempotencyKey) ?? throw $e;
            }

            throw $e;
        }
    }

    private function confirm(User $by, Stay $stay, PaymentMethod $method, string $idempotencyKey, ?Money $tendered, ?string $referenceNo, bool $keepChangeAsDeposit): Stay
    {
        // Lock order: shift → room → stay (DATABASE_SCHEMA §8).
        $shift = $this->currentShift->forPayment($stay->branch_id);
        $room = Room::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stay->room_id);
        $locked = Stay::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stay->id);
        $now = $this->clock->now();

        if ($locked->state !== StayState::Held || $locked->hold_expires_at === null || ! $locked->hold_expires_at->greaterThan($now)) {
            throw HoldExpired::make();
        }

        if ($room->status !== RoomStatus::Available || ! $room->is_active) {
            throw RoomNotAvailable::room($room->number, $room->id);
        }

        // A discount the guest ticked at the kiosk must be checked (ID verified or revoked) first.
        if ($locked->discount_unverified) {
            throw ActionRefused::because('Check the guest’s ID for the discount they claimed, or revoke it, before taking payment.');
        }

        $branch = Branch::query()->findOrFail($locked->branch_id);
        $discount = $locked->discount_id === null ? null : Discount::query()->withoutGlobalScopes()->find($locked->discount_id);
        $quote = $this->pricing->quoteStay(
            RateCard::forBranch($branch), $locked->room_type_id, $locked->stay_kind, (int) ($locked->hours ?? $locked->nights ?? $locked->days ?? 1), $now,
            $discount === null ? null : DiscountTerms::of($discount),
            roomId: $room->id, floorId: $room->floor_id,
        );

        $this->checkIn->assertTendered($quote, $locked, $method, $tendered);
        $this->checkIn->assertAccepted($locked->branch_id, $method, $referenceNo);

        $locked->update([
            'state' => StayState::CheckedIn,
            'quoted_amount' => $quote->netRoomAmount(),
            'checked_in_at' => $now,
            'expected_checkout_at' => $quote->expectedCheckoutAt,
            'cycle_hours_used' => $quote->cycleHoursUsed,
            'checkin_shift_id' => $shift->id,
            'checked_in_by' => $by->id,
        ]);

        $this->checkIn->write($shift, $locked, $room, $by, $quote, $discount, $method, $tendered, $referenceNo, $keepChangeAsDeposit, $idempotencyKey);

        return $locked;
    }
}
