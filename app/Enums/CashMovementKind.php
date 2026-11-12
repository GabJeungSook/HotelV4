<?php

declare(strict_types=1);

namespace App\Enums;

/** cash_movements.kind */
enum CashMovementKind: string
{
    case Expense = 'expense';
    case Remittance = 'remittance';
    case ShortagePayment = 'shortage_payment';

    public function label(): string
    {
        return match ($this) {
            self::Expense => 'Expense',
            self::Remittance => 'Remittance',
            self::ShortagePayment => 'Shortage payment',
        };
    }
}
