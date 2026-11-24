<?php

declare(strict_types=1);

namespace App\Enums;

/** hall_packages.kind (DATABASE_SCHEMA §4.14): what a package covers, for grouping in the quote. */
enum HallPackageKind: string
{
    case Venue = 'venue';
    case Food = 'food';
    case Host = 'host';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Venue => 'Venue',
            self::Food => 'Food',
            self::Host => 'Host',
            self::Other => 'Other',
        };
    }
}
