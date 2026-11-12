<?php

declare(strict_types=1);

namespace App\Exceptions;

/** An expense or remittance above the cash expected in the drawer. Never states the amount (TRD §4.12). */
final class NotEnoughCash extends DomainException
{
    public static function make(): self
    {
        return new self('There is not that much cash in the drawer.');
    }
}
