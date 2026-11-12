<?php

declare(strict_types=1);

namespace App\Enums;

/** pos_orders.mode */
enum PosMode: string
{
    case WalkIn = 'walk_in';
    case RoomCharge = 'room_charge';

    public function label(): string
    {
        return match ($this) {
            self::WalkIn => 'Walk-in',
            self::RoomCharge => 'Charge to room',
        };
    }
}
