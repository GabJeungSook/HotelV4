<?php

declare(strict_types=1);

namespace App\Enums;

/** stays.stay_kind, rates.stay_kind (hourly and overnight only) */
enum StayKind: string
{
    case Hourly = 'hourly';
    case Overnight = 'overnight';
    case LongStay = 'long_stay';

    public function label(): string
    {
        return match ($this) {
            self::Hourly => 'Hourly',
            self::Overnight => 'Overnight',
            self::LongStay => 'Long stay',
        };
    }
}
