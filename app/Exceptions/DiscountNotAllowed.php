<?php

declare(strict_types=1);

namespace App\Exceptions;

/** A discount limited to some rates (all_rates = 0) was used on another rate (TRD §4.2). */
final class DiscountNotAllowed extends DomainException
{
    public static function onRate(string $discount, string $option): self
    {
        return new self("{$discount} cannot be used on {$option}.");
    }
}
