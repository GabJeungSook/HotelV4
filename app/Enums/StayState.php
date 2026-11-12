<?php

declare(strict_types=1);

namespace App\Enums;

/** stays.state */
enum StayState: string
{
    case Reserved = 'reserved';
    case Held = 'held';
    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Reserved',
            self::Held => 'Held',
            self::CheckedIn => 'Checked in',
            self::CheckedOut => 'Checked out',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::NoShow => 'No-show',
        };
    }
}
