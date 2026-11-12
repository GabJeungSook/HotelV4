<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The PIN unlock form was refused: wrong username or password, or not a supervisor or admin (TRD §4.12). */
final class UnlockRefused extends DomainException
{
    public static function make(): self
    {
        return new self('Username or password not recognised, or this account cannot unlock PIN entry.');
    }

    public static function notLocked(): self
    {
        return new self('PIN entry is not locked on this computer.');
    }
}
