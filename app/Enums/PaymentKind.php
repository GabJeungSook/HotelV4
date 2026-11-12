<?php

declare(strict_types=1);

namespace App\Enums;

/** payments.kind */
enum PaymentKind: string
{
    case Payment = 'payment';
    case DepositIn = 'deposit_in';
    case DepositRefund = 'deposit_refund';
    case DepositApplied = 'deposit_applied';
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Payment',
            self::DepositIn => 'Deposit taken',
            self::DepositRefund => 'Deposit returned',
            self::DepositApplied => 'Paid from deposit',
            self::Refund => 'Refund',
        };
    }
}
