<?php

declare(strict_types=1);

namespace App\Enums;

/** rooms.status, room_status_logs.from_status / to_status */
enum RoomStatus: string
{
    case Available = 'available';
    case Occupied = 'occupied';
    case Uncleaned = 'uncleaned';
    case Cleaning = 'cleaning';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Occupied => 'Occupied',
            self::Uncleaned => 'Uncleaned',
            self::Cleaning => 'Cleaning',
            self::Maintenance => 'Maintenance',
        };
    }
}
