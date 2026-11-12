<?php

declare(strict_types=1);

namespace App\Enums;

/** cleaning_tasks.trigger_kind */
enum CleaningTrigger: string
{
    case CheckOut = 'check_out';
    case TransferOut = 'transfer_out';

    public function label(): string
    {
        return match ($this) {
            self::CheckOut => 'Check-out',
            self::TransferOut => 'Transfer out',
        };
    }
}
