<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Two open stays for one room: the duplicate on stays_one_active_per_room (screen 53). */
final class RoomTaken extends DomainException
{
    public static function room(string $number): self
    {
        return new self("Room {$number} was just taken.");
    }
}
