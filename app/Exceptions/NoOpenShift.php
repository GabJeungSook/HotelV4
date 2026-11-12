<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The branch has no open shift (redirect to screen 2). */
final class NoOpenShift extends DomainException
{
    public static function make(): self
    {
        return new self('No shift is open. Open a shift first.');
    }
}
