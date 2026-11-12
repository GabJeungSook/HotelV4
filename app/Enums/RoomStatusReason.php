<?php

declare(strict_types=1);

namespace App\Enums;

/** room_status_logs.reason (VARCHAR, values listed in DATABASE_SCHEMA §4.4) */
enum RoomStatusReason: string
{
    case CheckIn = 'check_in';
    case CheckOut = 'check_out';
    case TransferOut = 'transfer_out';
    case CleanStart = 'clean_start';
    case CleanFinish = 'clean_finish';
    case Admin = 'admin';
    case Cancel = 'cancel';

    public function label(): string
    {
        return match ($this) {
            self::CheckIn => 'Check-in',
            self::CheckOut => 'Check-out',
            self::TransferOut => 'Transfer out',
            self::CleanStart => 'Cleaning started',
            self::CleanFinish => 'Cleaning finished',
            self::Admin => 'Admin',
            self::Cancel => 'Cancelled',
        };
    }
}
