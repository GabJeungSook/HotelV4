<?php

declare(strict_types=1);

namespace App\Enums;

/** cleaning_tasks.result */
enum CleaningResult: string
{
    case Ready = 'ready';
    case Problem = 'problem';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::Problem => 'Problem',
        };
    }
}
