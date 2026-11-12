<?php

declare(strict_types=1);

namespace App\Enums;

/** daily_report_lines.section */
enum ReportSection: string
{
    case RoomType = 'room_type';
    case StayOption = 'stay_option';
    case Product = 'product';
    case Discount = 'discount';

    public function label(): string
    {
        return match ($this) {
            self::RoomType => 'Room type',
            self::StayOption => 'Stay option',
            self::Product => 'Product',
            self::Discount => 'Discount',
        };
    }
}
