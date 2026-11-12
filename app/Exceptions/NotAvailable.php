<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A room or room type is not free for a period (58, APP_FLOW §6 case 48), or a walk-in would run
 * into a reservation of the room (6).
 */
final class NotAvailable extends DomainException
{
    public static function make(string $what): self
    {
        return new self("The room is not free for {$what}.");
    }

    /** 58 with a specific room: "Room 3A is not free for those dates." */
    public static function room(string $number): self
    {
        return new self("Room {$number} is not free for those dates. Pick another room or other dates.");
    }

    /** 58 with "any room": the type is full for at least part of the stay. */
    public static function type(string $type, string $when): self
    {
        return new self("No {$type} room is free on {$when}. Pick another room type or other dates.");
    }

    /** 6 and 28: the room is reserved before this stay would end. */
    public static function reserved(string $number, string $when): self
    {
        return new self("Room {$number} is reserved from {$when}. Pick another room.");
    }
}
