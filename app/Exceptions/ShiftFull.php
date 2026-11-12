<?php

declare(strict_types=1);

namespace App\Exceptions;

/** JoinShift at max_frontdesks_per_shift (screen 2b, APP_FLOW §6 case 16). */
final class ShiftFull extends DomainException
{
    public static function limit(int $max): self
    {
        $words = [1 => 'one front desk', 2 => 'two front desks', 3 => 'three front desks', 4 => 'four front desks'];

        return new self('This shift already has '.($words[$max] ?? "{$max} front desks").'.');
    }
}
