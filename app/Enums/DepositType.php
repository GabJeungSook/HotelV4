<?php

declare(strict_types=1);

namespace App\Enums;

/** payments.deposit_type */
enum DepositType: string
{
    case Key = 'key';
    case Guest = 'guest';

    public function label(): string
    {
        return match ($this) {
            self::Key => 'Key deposit',
            self::Guest => 'Guest deposit',
        };
    }
}
