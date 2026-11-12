<?php

declare(strict_types=1);

namespace App\Exceptions;

/** A stay option the branch does not sell: a switched-off stay kind, or a length outside its limits (TRD §4.2). */
final class InvalidStayOption extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
