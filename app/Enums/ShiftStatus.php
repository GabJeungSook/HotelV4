<?php

declare(strict_types=1);

namespace App\Enums;

/** shift_sessions.status */
enum ShiftStatus: string
{
    case Open = 'open';
    case Closing = 'closing';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closing => 'Closing',
            self::Closed => 'Closed',
        };
    }
}
