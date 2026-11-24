<?php

declare(strict_types=1);

namespace App\Enums;

/** hall_bookings.status (DATABASE_SCHEMA §4.14): the booking lifecycle. */
enum HallBookingStatus: string
{
    case Reserved = 'reserved';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Reserved',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }
}
