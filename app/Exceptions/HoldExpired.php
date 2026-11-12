<?php

declare(strict_types=1);

namespace App\Exceptions;

/** A kiosk hold passed hold_expires_at before it was confirmed (screen 7). */
final class HoldExpired extends DomainException
{
    public static function make(): self
    {
        return new self('This hold has expired.');
    }
}
