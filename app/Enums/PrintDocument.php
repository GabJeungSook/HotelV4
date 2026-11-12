<?php

declare(strict_types=1);

namespace App\Enums;

/** print_logs.document */
enum PrintDocument: string
{
    case CheckinSlip = 'checkin_slip';
    case Receipt = 'receipt';
    case ShiftSlip = 'shift_slip';
    case ZReport = 'z_report';
    case DayZ = 'day_z';
    case Report = 'report';

    public function label(): string
    {
        return match ($this) {
            self::CheckinSlip => 'Check-in slip',
            self::Receipt => 'Receipt',
            self::ShiftSlip => 'Shift slip',
            self::ZReport => 'Z-report',
            self::DayZ => 'Day Z',
            self::Report => 'Report',
        };
    }
}
