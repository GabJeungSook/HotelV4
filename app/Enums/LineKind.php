<?php

declare(strict_types=1);

namespace App\Enums;

/** folio_lines.kind */
enum LineKind: string
{
    case Room = 'room';
    case Extension = 'extension';
    case TransferDifference = 'transfer_difference';
    case FoodDrink = 'food_drink';
    case Amenity = 'amenity';
    case Damage = 'damage';
    case EventHall = 'event_hall';
    case Discount = 'discount';
    case ForfeitedDeposit = 'forfeited_deposit';

    public function label(): string
    {
        return match ($this) {
            self::Room => 'Room',
            self::Extension => 'Extension',
            self::TransferDifference => 'Transfer difference',
            self::FoodDrink => 'Food & drinks',
            self::Amenity => 'Amenities',
            self::Damage => 'Damages',
            self::EventHall => 'Event hall',
            self::Discount => 'Discount',
            self::ForfeitedDeposit => 'Forfeited deposit',
        };
    }
}
