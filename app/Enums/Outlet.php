<?php

declare(strict_types=1);

namespace App\Enums;

/** products.outlet */
enum Outlet: string
{
    case Frontdesk = 'frontdesk';
    case Kitchen = 'kitchen';
    case Pub = 'pub';

    public function label(): string
    {
        return match ($this) {
            self::Frontdesk => 'Front desk',
            self::Kitchen => 'Kitchen',
            self::Pub => 'Pub',
        };
    }
}
