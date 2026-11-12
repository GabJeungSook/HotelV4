<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The room is not available for a check-in or a transfer (screen 53). */
final class RoomNotAvailable extends DomainException
{
    public static function room(string $number): self
    {
        return new self("Room {$number} is not available.");
    }
}
