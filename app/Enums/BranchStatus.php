<?php

declare(strict_types=1);

namespace App\Enums;

/** branches.status */
enum BranchStatus: string
{
    case SettingUp = 'setting_up';
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::SettingUp => 'Setting up',
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }
}
