<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Enums\RoomStatus;
use App\Enums\StayKind;
use App\Enums\StaySource;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Exceptions\RoomTaken;
use App\Models\Branch;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Queries\RoomQuery;
use App\Services\HoldExpiry;
use App\Services\KioskOffer;
use App\Services\Pricing\RateCard;
use App\Services\RoomRotation;
use App\Services\StayNumber;
use App\Support\Clock;
use App\Support\DuplicateKey;
use App\Support\Transaction;
use Illuminate\Database\QueryException;

/**
 * "Hold my room" at the kiosk (29 → 30, APP_FLOW §4.18 steps 5–7, SYSTEM_DESIGN §6.5).
 *
 * The kiosk sends the room type, the hours, the name, the mobile and a key; never a room or a
 * price. Inside one transaction: holds of the branch that ran out are expired, RoomRotation
 * picks the room again, the room is locked, PricingService prices the stay with the kiosk
 * discount, and a `held` stay is written with hold_expires_at = now + kiosk_hold_minutes and
 * the stay code (branch row locked last). No ledger row is written and no shift is needed.
 *
 * A room taken a moment earlier (a duplicate on stays_one_active_per_room, or a room no longer
 * available) is RoomTaken: the kiosk goes back to 28, where rotation offers the next room. A
 * repeated key returns the first hold.
 */
final class HoldRoom
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RoomQuery $rooms,
        private readonly RoomRotation $rotation,
        private readonly KioskOffer $offer,
        private readonly HoldExpiry $holds,
        private readonly StayNumber $numbers,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $kiosk, int $roomTypeId, StayKind $kind, int $quantity, string $guestName, ?string $guestMobile, string $idempotencyKey): Stay
    {
        $existing = $this->replay($idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        $name = trim((string) preg_replace('/\s+/', ' ', $guestName));
        $mobile = $guestMobile === null ? null : (string) preg_replace('/\D/', '', $guestMobile);

        if (preg_match_all('/\p{L}/u', $name) < 3) {
            throw ActionRefused::because('Type your name, at least 3 letters.');
        }

        if ($mobile === '') {
            $mobile = null;
        }

        if ($mobile !== null && ! preg_match('/^09\d{9}$/', $mobile)) {
            throw ActionRefused::because('Enter all 11 digits, or leave it blank.');
        }

        try {
            return $this->transaction->run(fn (): Stay => $this->hold((int) $kiosk->branch_id, $roomTypeId, $kind, $quantity, $name, $mobile, $idempotencyKey));
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'stays_idempotency_unique')) {
                return $this->replay($idempotencyKey) ?? throw $e;
            }

            throw $e;
        }
    }

    private function hold(int $branchId, int $roomTypeId, StayKind $kind, int $quantity, string $name, ?string $mobile, string $idempotencyKey): Stay
    {
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($branchId);
        $kindEnabled = $kind === StayKind::LongStay ? $branch->long_stay_enabled : $branch->hourly_enabled;

        if (! $branch->kiosk_enabled || ! $kindEnabled) {
            throw ActionRefused::because('The kiosk is not taking holds. Please check in at the front desk.');
        }

        $type = RoomType::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('is_active', true)->findOrFail($roomTypeId);
        $card = RateCard::forBranch($branch);
        $offered = $kind === StayKind::LongStay
            ? ($quantity >= 1 && $quantity <= $this->offer->maxDays($branch, $card, $type->id, $this->clock->now()))
            : in_array($quantity, $this->offer->hours($card, $type->id), true);

        if (! $offered) {
            throw ActionRefused::because("That stay is not offered for {$type->name} rooms.");
        }

        $now = $this->clock->now();
        $expiresAt = $now->addMinutes($branch->kiosk_hold_minutes);
        $this->holds->expireDue($branchId);

        // The stay can start as late as the end of the hold, so that is the period to keep clear.
        ['quote' => $latest] = $this->offer->quote($branchId, $card, $type->id, $kind, $quantity, $expiresAt);
        $next = $this->rotation->next($this->rooms->kioskCandidates($branchId, $type->id, $now, $latest->expectedCheckoutAt));

        if ($next === null) {
            throw RoomTaken::type($type->name);
        }

        $room = Room::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($next->id);

        if ($room->status !== RoomStatus::Available || ! $room->is_active) {
            throw RoomTaken::room($room->number, $room->id);
        }

        ['quote' => $quote, 'discount' => $discount] = $this->offer->quote($branchId, $card, $type->id, $kind, $quantity, $now, $room->id, $room->floor_id);
        $code = $this->numbers->next($branch);

        try {
            return Stay::query()->create([
                'branch_id' => $branchId,
                'code' => $code,
                'state' => StayState::Held,
                'source' => StaySource::Kiosk,
                'room_id' => $room->id,
                'room_type_id' => $type->id,
                'guest_name' => $name,
                'guest_mobile' => $mobile,
                'stay_kind' => $kind,
                'hours' => $kind === StayKind::Hourly ? $quantity : null,
                'nights' => $kind === StayKind::Overnight ? $quantity : null,
                'days' => $kind === StayKind::LongStay ? $quantity : null,
                'discount_id' => $discount?->id,
                'quoted_amount' => $quote->netRoomAmount(),
                'hold_expires_at' => $expiresAt,
                // The latest the stay could end; ConfirmKioskHold sets it again from the check-in.
                'expected_checkout_at' => $latest->expectedCheckoutAt,
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'stays_one_active_per_room')) {
                throw RoomTaken::room($room->number, $room->id);
            }

            throw $e;
        }
    }

    private function replay(string $idempotencyKey): ?Stay
    {
        return Stay::query()->withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();
    }
}
