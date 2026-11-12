<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Support\Money;

/** PricingService::quoteExtension (TRD §4.3). $parts says how the amount was worked out (screen 12). */
final class ExtensionQuote
{
    /** @param  list<array{label: string, amount: Money}>  $parts */
    public function __construct(
        public readonly int $hours,
        public readonly Money $amount,
        public readonly int $cycleHoursUsed,
        public readonly array $parts,
    ) {}
}
