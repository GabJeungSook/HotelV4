<?php

declare(strict_types=1);

namespace App\Exceptions;

/** A stock-tracked product has fewer pieces than asked (screens 10, 19). */
final class InsufficientStock extends DomainException
{
    public static function product(string $name, int $onHand): self
    {
        return new self("{$name} is out of stock ({$onHand} left). Nothing was added.");
    }
}
