<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The shift is closing: the outgoing team cannot write money rows (TRD §4.12). */
final class ShiftClosing extends DomainException
{
    public static function make(?string $closer = null): self
    {
        return new self(($closer === null ? 'The shift is being closed' : "{$closer} is closing the shift").'. Nothing was saved.');
    }
}
