<?php

declare(strict_types=1);

namespace App\Exceptions;

/** A second open shift hit shift_sessions_one_open (screen 2 shows Join instead). */
final class ShiftAlreadyOpen extends DomainException
{
    public static function make(): self
    {
        return new self('A shift is already open. Join it instead.');
    }
}
