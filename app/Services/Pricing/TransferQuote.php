<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Support\Money;

/** PricingService::quoteTransfer (TRD §4.4). Positive difference: collect; negative: guest deposit credit. */
final class TransferQuote
{
    public function __construct(
        public readonly Money $newPrice,
        public readonly Money $alreadyCharged,
        public readonly Money $difference,
    ) {}
}
