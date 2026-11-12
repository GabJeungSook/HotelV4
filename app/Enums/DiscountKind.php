<?php

declare(strict_types=1);

namespace App\Enums;

/** discounts.kind */
enum DiscountKind: string
{
    case Fixed = 'fixed';
    case Percent = 'percent';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed amount',
            self::Percent => 'Percent',
        };
    }
}
