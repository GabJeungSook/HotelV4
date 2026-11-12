<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\StayState;
use App\Exceptions\DiscountNotAllowed;
use App\Exceptions\RateNotFound;
use App\Models\Branch;
use App\Models\Discount;
use App\Models\Floor;
use App\Models\Stay;
use App\Services\Pricing\DiscountTerms;
use App\Services\Pricing\RateCard;
use App\Services\PricingService;
use App\Support\Clock;

/**
 * Props of the kiosk holds sheet (5) and the confirm screen (7), APP_FLOW §4.6. The amount to
 * collect is worked out by PricingService for a check-in now, with the room, hours and discount
 * the guest chose at the kiosk. A hold's amounts are a quote, not a sale; nothing here reads the
 * shift's money.
 */
final class HoldScreenQuery
{
    public function __construct(
        private readonly Clock $clock,
        private readonly StayQuery $stays,
        private readonly PricingService $pricing,
        private readonly StayScreenQuery $screens,
    ) {}

    /**
     * The holds sheet (5): live holds by time left, then the ones that expired in the last hour.
     *
     * @return list<array<string, mixed>>
     */
    public function holds(int $branchId): array
    {
        $now = $this->clock->now();
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($branchId);
        $deposit = $branch->deposit_enabled ? ($branch->deposit_amount?->centavos() ?? 0) : 0;

        return array_map(fn (Stay $s): array => [
            'id' => $s->id,
            'code' => $s->code,
            'room' => $s->room?->number,
            'guest' => $s->guest_name,
            'type' => $s->roomType->name ?? '',
            'stay' => $this->screens->optionLabel($s),
            'to_collect' => ($s->quoted_amount?->centavos() ?? 0) + $deposit,
            'expires_at' => $s->hold_expires_at?->toIso8601String(),
            'seconds_left' => $s->hold_expires_at === null ? 0 : max(0, $s->hold_expires_at->getTimestamp() - $now->getTimestamp()),
            'expired' => $s->state === StayState::Expired || ($s->hold_expires_at !== null && ! $s->hold_expires_at->greaterThan($now)),
        ], $this->stays->kioskHolds($branchId, $now));
    }

    /**
     * Confirm a kiosk hold (7): what the guest picked, the price for a check-in now, and the
     * payment methods. `expired` is true once the hold is no longer held or has run out.
     *
     * @return array<string, mixed>
     */
    public function confirm(Stay $stay): array
    {
        $now = $this->clock->now();
        $branch = Branch::query()->findOrFail($stay->branch_id);
        $stay->loadMissing(['room' => fn ($q) => $q->withoutGlobalScopes(), 'roomType' => fn ($q) => $q->withoutGlobalScopes()]);
        $discount = $stay->discount_id === null ? null : Discount::query()->withoutGlobalScopes()->find($stay->discount_id);
        $floor = $stay->room === null ? null : Floor::query()->withoutGlobalScopes()->find($stay->room->floor_id);

        $lines = [];
        $total = null;
        $missing = null;

        try {
            $quote = $this->pricing->quoteStay(
                RateCard::forBranch($branch), $stay->room_type_id, $stay->stay_kind, (int) ($stay->hours ?? 1), $now,
                $discount === null ? null : DiscountTerms::of($discount),
            );
            $lines[] = ['label' => "Room · {$quote->description}", 'amount' => $quote->roomAmount->centavos()];

            if ($quote->discountAmount->isPositive()) {
                $lines[] = ['label' => $discount->name ?? 'Discount', 'amount' => $quote->discountAmount->negate()->centavos()];
            }

            if ($quote->depositAmount->isPositive()) {
                $lines[] = ['label' => "{$branch->deposit_name} deposit", 'amount' => $quote->depositAmount->centavos()];
            }

            $total = $quote->totalToCollect()->centavos();
            $discountText = $quote->discountAmount->isPositive() ? $quote->discountAmount->format().' applied' : 'None';
        } catch (RateNotFound|DiscountNotAllowed $e) {
            $missing = $e->getMessage();
            $discountText = $discount === null ? 'None' : $discount->name;
        }

        return [
            'hold' => [
                'id' => $stay->id,
                'code' => $stay->code,
                'guest_name' => $stay->guest_name,
                'room' => $stay->room?->number,
                'type' => $stay->roomType->name ?? '',
                'floor' => $floor === null ? '' : $floor->number.'F',
                'stay' => $this->screens->optionLabel($stay),
                'discount' => $discountText,
                'expires_at' => $stay->hold_expires_at?->toIso8601String(),
                'expired' => $stay->state !== StayState::Held || $stay->hold_expires_at === null || ! $stay->hold_expires_at->greaterThan($now),
                'state' => $stay->state->value,
            ],
            'lines' => $lines,
            'total' => $total,
            'missing' => $missing,
            'methods' => $this->screens->methods($branch, false),
            'now' => $now->toIso8601String(),
        ];
    }
}
