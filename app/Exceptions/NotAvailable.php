<?php

declare(strict_types=1);

namespace App\Exceptions;

/** A reservation overlaps another stay of the room (screen 58). */
final class NotAvailable extends DomainException
{
    public static function make(string $what): self
    {
        return new self("The room is not free for {$what}.");
    }
}
