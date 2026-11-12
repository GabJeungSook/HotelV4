<?php

declare(strict_types=1);

namespace App\Enums;

/** payments.method, daily_payment_summaries.method, branches.payment_methods */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Gcash = 'gcash';
    case Card = 'card';
    case Deposit = 'deposit';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Gcash => 'GCash',
            self::Card => 'Card',
            self::Deposit => 'Deposit',
        };
    }
}
