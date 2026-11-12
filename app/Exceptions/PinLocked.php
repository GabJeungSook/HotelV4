<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * PIN entry is refused: the computer has an open pin_locks row after ten wrong tries in a
 * row (TRD §4.12), or one approver gave five wrong PINs in 15 minutes (TRD §9).
 */
final class PinLocked extends DomainException
{
    public static function computer(): self
    {
        return new self('PIN entry is locked on this computer. Ask a supervisor to unlock it.');
    }

    public static function approver(string $name, int $minutes): self
    {
        return new self("Approvals by {$name} are locked for {$minutes} minutes after too many wrong PINs.");
    }
}
