<?php

declare(strict_types=1);

namespace App\Enums;

/** branches.vat_mode — how a VAT-registered branch's VAT is set and reported. */
enum VatMode: string
{
    /** A percentage of the (VAT-inclusive) sale, split per line: vatable = amount / (1 + rate). */
    case Percent = 'percent';

    /** A fixed peso amount per taxable transaction (stay or sale); reported as the VAT figure. */
    case Amount = 'amount';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Percentage',
            self::Amount => 'Fixed amount',
        };
    }
}
