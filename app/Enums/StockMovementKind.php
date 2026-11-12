<?php

declare(strict_types=1);

namespace App\Enums;

/** stock_movements.kind */
enum StockMovementKind: string
{
    case Receive = 'receive';
    case Sale = 'sale';
    case SaleReversal = 'sale_reversal';
    case Wastage = 'wastage';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Receive => 'Received',
            self::Sale => 'Sold',
            self::SaleReversal => 'Sale reversed',
            self::Wastage => 'Wastage',
            self::Adjustment => 'Adjustment',
        };
    }
}
