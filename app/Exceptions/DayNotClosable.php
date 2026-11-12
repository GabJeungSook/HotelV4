<?php

declare(strict_types=1);

namespace App\Exceptions;

/** CloseDay is refused (screen 38b). */
final class DayNotClosable extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
