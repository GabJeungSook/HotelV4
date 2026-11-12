<?php

declare(strict_types=1);

namespace App\Enums;

/** reasons.kind */
enum ReasonKind: string
{
    case VoidLine = 'void_line';
    case CancelStay = 'cancel_stay';
    case Variance = 'variance';
    case VoidCashMovement = 'void_cash_movement';
    case Wastage = 'wastage';
    case CancelReservation = 'cancel_reservation';

    public function label(): string
    {
        return match ($this) {
            self::VoidLine => 'Void line',
            self::CancelStay => 'Cancel stay',
            self::Variance => 'Cash variance',
            self::VoidCashMovement => 'Void cash entry',
            self::Wastage => 'Wastage',
            self::CancelReservation => 'Cancel reservation',
        };
    }
}
