<?php

declare(strict_types=1);

namespace App\Enums;

/** products.kind */
enum ProductKind: string
{
    case FoodDrink = 'food_drink';
    case Amenity = 'amenity';
    case Damage = 'damage';

    public function label(): string
    {
        return match ($this) {
            self::FoodDrink => 'Food & drinks',
            self::Amenity => 'Amenity',
            self::Damage => 'Damage',
        };
    }
}
