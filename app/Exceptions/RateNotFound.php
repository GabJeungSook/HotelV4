<?php

declare(strict_types=1);

namespace App\Exceptions;

/** No rate for the stay option. Nothing is ever charged ₱0 (screen 52). */
final class RateNotFound extends DomainException
{
    public static function for(string $what): self
    {
        return new self("There is no rate for {$what}. Ask an admin to add it in Rates.");
    }
}
