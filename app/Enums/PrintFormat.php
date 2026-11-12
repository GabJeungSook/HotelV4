<?php

declare(strict_types=1);

namespace App\Enums;

/** print_logs.format */
enum PrintFormat: string
{
    case Print = 'print';
    case Pdf = 'pdf';
    case Csv = 'csv';

    public function label(): string
    {
        return match ($this) {
            self::Print => 'Print',
            self::Pdf => 'PDF',
            self::Csv => 'CSV',
        };
    }
}
