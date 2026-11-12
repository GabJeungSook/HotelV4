<?php

declare(strict_types=1);

namespace App\Enums;

/** cleaning_tasks.status */
enum CleaningStatus: string
{
    case Queued = 'queued';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Reset = 'reset';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::InProgress => 'In progress',
            self::Done => 'Done',
            self::Reset => 'Reset',
        };
    }
}
