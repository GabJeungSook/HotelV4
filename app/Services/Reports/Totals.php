<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * Reading and adding up the frozen `totals` JSON of shifts (DATABASE_SCHEMA §4.7). A missing
 * key reads as 0, so a shift frozen before a key existed still prints every row. A day's paper
 * is the sum of its shifts' totals (TRD §4.10): figures are added, lists are merged by name or
 * person, document ranges keep the first first and the last last.
 */
final class Totals
{
    /** @param  array<array-key, mixed>  $totals */
    public static function int(array $totals, string $path): int
    {
        $value = self::get($totals, $path);

        return is_int($value) ? $value : 0;
    }

    /**
     * @param  array<array-key, mixed>  $totals
     * @return array<array-key, mixed>
     */
    public static function list(array $totals, string $path): array
    {
        $value = self::get($totals, $path);

        return is_array($value) ? $value : [];
    }

    /** @param  array<array-key, mixed>  $totals */
    public static function get(array $totals, string $path): mixed
    {
        $value = $totals;

        foreach (explode('.', $path) as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * The totals of several shifts added together, in shift order (oldest first).
     *
     * @param  list<array<array-key, mixed>>  $shifts
     * @return array<string, mixed>
     */
    public static function sum(array $shifts): array
    {
        $sum = [];

        foreach ($shifts as $totals) {
            foreach ($totals as $key => $value) {
                $sum[$key] = match ($key) {
                    'discounts' => self::mergeBy($sum[$key] ?? [], is_array($value) ? $value : [], 'name', ['count', 'amount']),
                    'by_user' => self::mergeBy($sum[$key] ?? [], is_array($value) ? $value : [], 'user_id', ['checkins', 'cash', 'non_cash', 'given_back']),
                    'documents' => self::documents($sum[$key] ?? [], is_array($value) ? $value : []),
                    'vat' => $value === null && ! isset($sum[$key]) ? null : self::add($sum[$key] ?? [], is_array($value) ? $value : []),
                    default => self::add($sum[$key] ?? null, $value),
                };
            }
        }

        if (isset($sum['vat']) && is_array($sum['vat']) && is_array($shifts[0]['vat'] ?? null)) {
            $sum['vat']['rate'] = $shifts[0]['vat']['rate'] ?? 0;
        }

        return $sum;
    }

    /** Numbers add, booleans count, nested arrays add key by key. */
    private static function add(mixed $a, mixed $b): mixed
    {
        if (is_bool($b)) {
            return (is_int($a) ? $a : 0) + ($b ? 1 : 0);
        }

        if (is_int($b)) {
            return (is_int($a) ? $a : 0) + $b;
        }

        if (is_array($b)) {
            $out = is_array($a) ? $a : [];

            foreach ($b as $k => $v) {
                $out[$k] = self::add($out[$k] ?? null, $v);
            }

            return $out;
        }

        return $a ?? $b;
    }

    /**
     * @param  array<array-key, mixed>  $into
     * @param  array<array-key, mixed>  $rows
     * @param  list<string>  $numbers
     * @return list<array<string, mixed>>
     */
    private static function mergeBy(array $into, array $rows, string $key, array $numbers): array
    {
        $out = [];

        foreach ([...$into, ...$rows] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (string) ($row[$key] ?? '');

            if (! isset($out[$id])) {
                $out[$id] = $row;

                continue;
            }

            foreach ($numbers as $n) {
                $out[$id][$n] = (int) ($out[$id][$n] ?? 0) + (int) ($row[$n] ?? 0);
            }
        }

        return array_values($out);
    }

    /**
     * @param  array<array-key, mixed>  $into
     * @param  array<array-key, mixed>  $next
     * @return array<string, mixed>
     */
    private static function documents(array $into, array $next): array
    {
        $out = [];

        foreach (['stays', 'pos_orders'] as $doc) {
            $a = is_array($into[$doc] ?? null) ? $into[$doc] : ['first' => null, 'last' => null, 'count' => 0];
            $b = is_array($next[$doc] ?? null) ? $next[$doc] : ['first' => null, 'last' => null, 'count' => 0];
            $out[$doc] = [
                'first' => $a['first'] ?? $b['first'] ?? null,
                'last' => $b['last'] ?? $a['last'] ?? null,
                'count' => (int) ($a['count'] ?? 0) + (int) ($b['count'] ?? 0),
            ];
        }

        return $out;
    }
}
