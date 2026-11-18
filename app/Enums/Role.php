<?php

declare(strict_types=1);

namespace App\Enums;

/** users.role */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Supervisor = 'supervisor';
    case Frontdesk = 'frontdesk';
    case Backoffice = 'backoffice';
    case Housekeeper = 'housekeeper';
    case Kiosk = 'kiosk';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Superadmin',
            self::Admin => 'Admin',
            self::Supervisor => 'Supervisor',
            self::Frontdesk => 'Front desk',
            self::Backoffice => 'Back office',
            self::Housekeeper => 'Housekeeper',
            self::Kiosk => 'Kiosk device',
        };
    }
}
