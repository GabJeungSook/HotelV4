<?php

declare(strict_types=1);

namespace App\Enums;

/** How a hall package or booking line is priced (DATABASE_SCHEMA §4.14). */
enum HallPricing: string
{
    /** Price per guest: the line amount is the headcount × unit. */
    case PerPax = 'per_pax';
    /** A flat price: the line amount is quantity × unit. */
    case Flat = 'flat';

    public function label(): string
    {
        return match ($this) {
            self::PerPax => 'Per pax',
            self::Flat => 'Flat',
        };
    }
}
