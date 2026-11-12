<?php

declare(strict_types=1);

namespace App\Enums;

/** products.report_group */
enum ReportGroup: string
{
    case Foods = 'foods';
    case Drinks = 'drinks';
    case Misc = 'misc';

    public function label(): string
    {
        return match ($this) {
            self::Foods => 'Foods',
            self::Drinks => 'Drinks',
            self::Misc => 'Miscellaneous',
        };
    }
}
