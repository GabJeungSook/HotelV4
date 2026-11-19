<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StayKind;
use App\Exceptions\DiscountNotAllowed;
use App\Exceptions\RateNotFound;
use App\Models\Branch;
use App\Models\Discount;
use App\Services\Pricing\DiscountTerms;
use App\Services\Pricing\RateCard;
use App\Services\Pricing\StayQuote;
use Carbon\CarbonImmutable;

/**
 * What the kiosk offers for a room type and a stay (28, 29, APP_FLOW §4.18 step 4): the price from
 * PricingService with the branch discount that is flagged for the kiosk and allowed on that rate
 * applied by itself. When several apply, the one worth most to the guest wins. The kiosk sells
 * hourly stays and, when the branch allows long stay, whole days (priced from the 24-hour rate).
 */
final class KioskOffer
{
    public function __construct(private readonly PricingService $pricing) {}

    /** @return array{quote: StayQuote, discount: Discount|null} */
    public function quote(int $branchId, RateCard $card, int $roomTypeId, StayKind $kind, int $quantity, CarbonImmutable $at): array
    {
        $best = $this->pricing->quoteStay($card, $roomTypeId, $kind, $quantity, $at);
        $bestDiscount = null;

        $discounts = Discount::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->where('kiosk_enabled', true)
            ->orderBy('id')
            ->get();

        foreach ($discounts as $discount) {
            try {
                $quote = $this->pricing->quoteStay($card, $roomTypeId, $kind, $quantity, $at, DiscountTerms::of($discount));
            } catch (DiscountNotAllowed) {
                continue;
            }

            if ($quote->discountAmount->greaterThan($best->discountAmount)) {
                $best = $quote;
                $bestDiscount = $discount;
            }
        }

        return ['quote' => $best, 'discount' => $bestDiscount];
    }

    /**
     * The hour options of a type: every hourly rate the type has, shortest first.
     *
     * @return list<int>
     */
    public function hours(RateCard $card, int $roomTypeId): array
    {
        $hours = array_keys($card->hourly[$roomTypeId] ?? []);
        sort($hours);

        return array_values(array_filter($hours, static fn (int $h): bool => $h >= 1 && $h <= 24));
    }

    /**
     * The most whole days a guest may book for long stay at the kiosk: the branch's longest stay
     * (settings), or 0 when the branch does not allow long stay or the day rate does not price.
     */
    public function maxDays(Branch $branch, RateCard $card, int $roomTypeId, CarbonImmutable $at): int
    {
        if (! $branch->long_stay_enabled) {
            return 0;
        }

        try {
            $this->pricing->quoteStay($card, $roomTypeId, StayKind::LongStay, 1, $at);
        } catch (RateNotFound) {
            return 0;
        }

        return (int) $branch->long_stay_max_days;
    }
}
