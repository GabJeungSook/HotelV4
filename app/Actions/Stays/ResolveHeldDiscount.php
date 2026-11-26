<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Enums\StayKind;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Models\Branch;
use App\Models\Discount;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\Pricing\RateCard;
use App\Services\PricingService;
use App\Support\Clock;
use App\Support\Transaction;

/**
 * The front desk resolves a discount a guest ticked at the kiosk (senior citizen, PWD) before
 * taking the money (screen 7). It is applied to the hold but unverified until an ID is shown:
 *
 *  - verify: the ID is valid, so the discount stays and the unverified flag is cleared.
 *  - revoke: no valid ID, so the discount is removed and the hold is re-priced without it.
 *
 * A held stay carries no ledger rows, so re-pricing is just a new quoted_amount; the sale is
 * written later by ConfirmKioskHold. One transaction with its audit_logs row.
 */
final class ResolveHeldDiscount
{
    public function __construct(
        private readonly Clock $clock,
        private readonly PricingService $pricing,
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $by, Stay $stay, bool $verified): Stay
    {
        return $this->transaction->run(function () use ($by, $stay, $verified): Stay {
            $locked = Stay::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stay->id);

            if ($locked->state !== StayState::Held) {
                throw ActionRefused::because('This hold is no longer open.');
            }

            if ($locked->discount_id === null || ! $locked->discount_unverified) {
                throw ActionRefused::because('There is no discount to check on this hold.');
            }

            $discount = Discount::query()->withoutGlobalScopes()->findOrFail($locked->discount_id);

            if ($verified) {
                $locked->update(['discount_unverified' => false]);
                $this->audit->record($by, (int) $locked->branch_id, 'discount.verified', 'stay', $locked->id, null, ['discount' => $discount->name]);

                return $locked;
            }

            // Revoke: drop the discount and re-price the hold at the plain rate.
            $before = $locked->quoted_amount?->centavos();
            $branch = Branch::query()->withoutGlobalScopes()->findOrFail($locked->branch_id);
            $room = $locked->room_id === null ? null : Room::query()->withoutGlobalScopes()->find($locked->room_id);
            $quote = $this->pricing->quoteStay(
                RateCard::forBranch($branch),
                $locked->room_type_id,
                $locked->stay_kind,
                $this->quantity($locked),
                $this->clock->now(),
                roomId: $room?->id,
                floorId: $room?->floor_id,
            );

            $locked->update([
                'discount_id' => null,
                'discount_unverified' => false,
                'quoted_amount' => $quote->netRoomAmount(),
            ]);

            $this->audit->record($by, (int) $locked->branch_id, 'discount.revoked', 'stay', $locked->id,
                ['discount' => $discount->name, 'quoted_amount' => $before],
                ['quoted_amount' => $quote->netRoomAmount()->centavos()],
            );

            return $locked;
        });
    }

    private function quantity(Stay $stay): int
    {
        return match ($stay->stay_kind) {
            StayKind::LongStay => (int) ($stay->days ?? 1),
            StayKind::Overnight => (int) ($stay->nights ?? 1),
            default => (int) ($stay->hours ?? 1),
        };
    }
}
