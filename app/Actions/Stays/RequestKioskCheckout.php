<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Models\Branch;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Support\Clock;
use App\Support\Transaction;

/**
 * A guest asks to check out at the lobby kiosk (reference: HotelV2). They give their room number
 * and stay code; this only flags the stay (checkout_requested_at) so the front desk knows they are
 * ready to leave. No money is written and the room is not freed — the desk still takes payment,
 * returns the deposit and key, and does the real checkout (CheckOutGuest). Idempotent: a second
 * request returns the same stay without changing the first time.
 */
final class RequestKioskCheckout
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $kiosk, string $roomNumber, string $code): Stay
    {
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($kiosk->branch_id);

        if (! $branch->kiosk_enabled || ! $branch->kiosk_checkout_enabled) {
            throw ActionRefused::because('Checkout at the kiosk is not available. Please see the front desk.');
        }

        return $this->transaction->run(function () use ($branch, $roomNumber, $code): Stay {
            $stay = $this->find($branch->id, $roomNumber, $code, lock: true);

            if ($stay->checkout_requested_at === null) {
                $stay->update(['checkout_requested_at' => $this->clock->now()]);
            }

            return $stay;
        });
    }

    /**
     * The front desk starts the checkout for a guest who did not use the kiosk (walked up to the
     * desk). Same flag, so the stay joins the checkout-requests sheet and the desk can settle and
     * check out. Idempotent; no money or room change.
     */
    public function byDesk(User $by, Stay $stay): Stay
    {
        return $this->transaction->run(function () use ($stay): Stay {
            $locked = Stay::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stay->id);

            if ($locked->state !== StayState::CheckedIn) {
                throw ActionRefused::because('This stay is not checked in.');
            }

            if ($locked->checkout_requested_at === null) {
                $locked->update(['checkout_requested_at' => $this->clock->now()]);
            }

            return $locked;
        });
    }

    /**
     * The checked-in stay for a room number and stay code in this branch, or a refusal. Used both
     * to show the bill (no lock) and to flag the request (locked).
     */
    public function find(int $branchId, string $roomNumber, string $code, bool $lock = false): Stay
    {
        $code = mb_strtoupper(trim($code));
        $roomNumber = trim($roomNumber);

        $query = Stay::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('code', $code)
            ->where('state', StayState::CheckedIn);

        if ($lock) {
            $query->lockForUpdate();
        }

        $stay = $query->first();
        $room = $stay === null || $stay->room_id === null ? null : Room::query()->withoutGlobalScopes()->find($stay->room_id);

        if ($stay === null || $room === null || $room->number !== $roomNumber) {
            throw ActionRefused::because('We could not find a stay for that room and code. Check your check-in slip, or see the front desk.');
        }

        return $stay;
    }
}
