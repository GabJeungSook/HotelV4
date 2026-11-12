<?php

declare(strict_types=1);

namespace App\Enums;

/** pin_attempts.context */
enum PinContext: string
{
    case SwitchUser = 'switch_user';
    case Approval = 'approval';
    case Handover = 'handover';

    public function label(): string
    {
        return match ($this) {
            self::SwitchUser => 'Switch user',
            self::Approval => 'Approval',
            self::Handover => 'Handover',
        };
    }
}
