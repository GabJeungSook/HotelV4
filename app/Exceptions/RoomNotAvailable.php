<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The room is not available for a check-in or a transfer (screen 53). */
final class RoomNotAvailable extends DomainException
{
    private function __construct(string $message, public readonly ?int $roomId)
    {
        parent::__construct($message);
    }

    public static function room(string $number, ?int $roomId = null): self
    {
        return new self("Room {$number} is not available.", $roomId);
    }
}
