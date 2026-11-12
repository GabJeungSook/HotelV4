<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\StayKind;
use App\Support\Money;
use Carbon\CarbonImmutable;

/** PricingService::quoteStay (TRD §4.2). */
final class StayQuote
{
    public function __construct(
        public readonly StayKind $kind,
        public readonly int $quantity,
        public readonly int $rateId,
        public readonly string $description,
        public readonly Money $roomAmount,
        public readonly Money $discountAmount,
        public readonly Money $depositAmount,
        public readonly CarbonImmutable $expectedCheckoutAt,
        public readonly int $cycleHoursUsed,
    ) {}

    /** Room amount after the discount: the stay's quoted_amount. */
    public function netRoomAmount(): Money
    {
        return $this->roomAmount->minus($this->discountAmount);
    }

    /** What to collect at check-in: room − discount + deposit. */
    public function totalToCollect(): Money
    {
        return $this->netRoomAmount()->plus($this->depositAmount);
    }
}
