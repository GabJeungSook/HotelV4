<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;

/** RoomStatusService refused a move that APP_FLOW §5.2 does not allow. */
final class RoomTransitionNotAllowed extends DomainException
{
    public static function move(string $room, RoomStatus $from, RoomStatus $to, RoomStatusReason $reason): self
    {
        return new self("Room {$room} cannot go from {$from->value} to {$to->value} ({$reason->value}).");
    }
}
