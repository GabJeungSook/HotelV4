<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Check-out with a balance left after damages are covered by the key deposit (screen 17). */
final class BalanceNotZero extends DomainException
{
    public static function make(): self
    {
        return new self('The guest still has a balance to pay. Collect it before checking out.');
    }
}
