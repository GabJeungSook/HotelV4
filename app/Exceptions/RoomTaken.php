<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Two open stays for one room: the duplicate on stays_one_active_per_room (screen 53). */
final class RoomTaken extends DomainException
{
    private function __construct(string $message, public readonly ?int $roomId)
    {
        parent::__construct($message);
    }

    public static function room(string $number, ?int $roomId = null): self
    {
        return new self("Room {$number} was just taken.", $roomId);
    }

    /** The kiosk (29): every room of the type was taken a moment ago. */
    public static function type(string $type): self
    {
        return new self("The last {$type} room was just taken.", null);
    }
}
