<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The shift is shift_stale_block_minutes past its defined end (screen 54). */
final class ShiftStale extends DomainException
{
    public static function make(): self
    {
        return new self('Payments and charges are stopped until this shift is closed.');
    }
}
