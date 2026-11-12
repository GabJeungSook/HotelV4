<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ActionRefused;

/**
 * The pieces of a blind count (screen 23, DATABASE_SCHEMA §4.8): how many of each bill in
 * config('hotel.cash_count.bills'), plus the coins as one total in centavos. The server adds
 * them up itself; a total sent by the browser is never read (TRD §4.12, APP_FLOW §6 case 73).
 */
final class CashPieces
{
    /** @return list<int> the bills in centavos, largest first */
    public static function bills(): array
    {
        $bills = config('hotel.cash_count.bills', []);

        return is_array($bills) ? array_values(array_filter($bills, 'is_int')) : [];
    }

    /**
     * Keeps only the known bills and "coins", as non-negative integers. Anything else in the
     * input, a "total" included, is dropped.
     *
     * @param  array<array-key, mixed>  $raw
     * @return array<string, int>
     */
    public static function normalize(array $raw): array
    {
        $pieces = [];

        foreach ([...self::bills(), 'coins'] as $key) {
            $value = $raw[(string) $key] ?? $raw[$key] ?? 0;

            if ($value === '') {
                $value = 0;
            }

            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                throw ActionRefused::because('Type whole numbers of pieces, and the coins as an amount.');
            }

            $pieces[(string) $key] = min((int) $value, $key === 'coins' ? 100_000_000 : 100_000);
        }

        return $pieces;
    }

    /** @param  array<string, int>  $pieces  normalized pieces */
    public static function total(array $pieces): Money
    {
        $total = Money::zero();

        foreach (self::bills() as $bill) {
            $total = $total->plus(Money::ofCentavos($bill)->times($pieces[(string) $bill] ?? 0)); // @phpstan-ignore nullCoalesce.offset
        }

        return $total->plus(Money::ofCentavos($pieces['coins'] ?? 0));
    }
}
