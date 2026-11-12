<?php

declare(strict_types=1);

namespace App\Enums;

/** stays.source */
enum StaySource: string
{
    case WalkIn = 'walk_in';
    case Kiosk = 'kiosk';
    case Reservation = 'reservation';

    public function label(): string
    {
        return match ($this) {
            self::WalkIn => 'Walk-in',
            self::Kiosk => 'Kiosk',
            self::Reservation => 'Reservation',
        };
    }
}
