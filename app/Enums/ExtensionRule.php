<?php

declare(strict_types=1);

namespace App\Enums;

/** branches.extension_rule */
enum ExtensionRule: string
{
    case Cycle = 'cycle';
    case PerHour = 'per_hour';
    case Block = 'block';

    public function label(): string
    {
        return match ($this) {
            self::Cycle => '24-hour cycle',
            self::PerHour => 'Per hour',
            self::Block => 'Per block',
        };
    }
}
