<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * The Z paper (37b) and the day paper (38b) as one array of sections and lines (TRD §4.10,
 * DESIGN_SYSTEM ZPaper). The screen, the 80 mm slip, the PDF and the CSV all render this array;
 * none of them computes a figure.
 *
 * Every figure comes from the frozen totals (or, for a preview, totals built live by the same
 * method). Each bold ("equals") line shows the stored figure and is checked against the
 * arithmetic of the lines above it; a line that does not add up is listed in `checks`, which
 * the screen shows as a red alert, so a wrong paper can never look right.
 */
final class ZPaper
{
    /**
     * @param  array<array-key, mixed>  $t  frozen (or live) totals; for a day, the sum of its shifts
     * @param  array{kind: 'shift'|'day', preview: bool, number: string|null, branch: string, title: string, opened: string|null, closed: string|null, printed: string|null, tin: string|null, registered_name: string|null, expected: int|null, counted: int|null, short: int, over: int, variance_note: string|null, grand_total_before: int, grand_total_after: int, signatures: list<array{name: string|null, role: string}>, shifts?: list<array<string, mixed>>}  $meta
     * @return array<string, mixed>
     */
    public static function build(array $t, array $meta): array
    {
        $checks = [];
        $equals = function (string $label, int $shown, int $sum) use (&$checks): void {
            if ($shown !== $sum) {
                $checks[] = "{$label} shows ".self::pesos($shown).' but the lines above add up to '.self::pesos($sum).'.';
            }
        };
        $isDay = $meta['kind'] === 'day';
        $i = fn (string $path): int => Totals::int($t, $path);

        // ── Sales and cash (left column) ─────────────────────────────────────────────
        $w = [];
        $gross = $i('gross_sales');
        $w[] = self::line('Gross sales', $gross, drill: 'gross');
        $running = $gross;

        foreach (Totals::list($t, 'discounts') as $d) {
            $amount = is_array($d) ? (int) ($d['amount'] ?? 0) : 0;
            $label = is_array($d) ? (string) ($d['label'] ?? $d['name'] ?? 'Discount') : 'Discount';
            $w[] = self::line("Less {$label}", -$amount, (int) ($d['count'] ?? 0), 'indent', drill: 'discount:'.($d['name'] ?? ''));
            $running -= $amount;
        }

        $net = $i('sales.total');
        $equals('Net sales', $net, $running);
        $w[] = self::line('Net sales', $net, style: 'equals', drill: 'net');

        $fwdOut = $i('unpaid.forwarded_out');
        $fwdIn = $i('unpaid.forwarded_in');
        $w[] = self::line('Less unpaid, forwarded out', -$fwdOut, $i('drawer.counts.unpaid_forwarded_out'), 'indent', drill: 'unpaid_out');
        $w[] = self::line('Plus paid for earlier shifts', $fwdIn, $i('drawer.counts.unpaid_forwarded_in'), 'indent', drill: 'unpaid_in');
        $collected = $i('unpaid.collected');
        $equals('Collected', $collected, $net - $fwdOut + $fwdIn);
        $w[] = self::line('Collected', $collected, style: 'equals', drill: 'collected');

        $running = $collected;
        foreach (['gcash' => 'Less GCash', 'card' => 'Less card', 'deposit' => 'Less paid from deposit'] as $method => $label) {
            $amount = $i("drawer.non_cash.{$method}");
            $w[] = self::line($label, -$amount, $i("drawer.non_cash_counts.{$method}"), 'indent', drill: "method:{$method}");
            $running -= $amount;
        }

        $cashCollected = $i('drawer.cash_collected');
        $equals('Cash collected', $cashCollected, $running);
        $w[] = self::line('Cash collected', $cashCollected, style: 'equals', drill: 'cash_collected');

        $running = $cashCollected;
        $taken = $i('drawer.deposits_taken.amount');
        $returned = $i('drawer.deposits_returned.amount');
        $w[] = self::line('Plus deposits taken', $taken, $i('drawer.deposits_taken.count'), 'indent', drill: 'deposits_taken');
        $w[] = self::line('Less deposits returned', -$returned, $i('drawer.deposits_returned.count'), 'indent', drill: 'deposits_returned');
        $running += $taken - $returned;

        // Guest deposits (money paid ahead, change kept, transfer credit) and any other cash
        // are printed only when not zero (Gab, 12 Nov), so every peso of the drawer is on paper.
        if (($guest = $i('drawer.guest_deposits.amount')) !== 0) {
            $w[] = self::line('Plus guest deposits, net', $guest, $i('drawer.guest_deposits.count'), 'indent', drill: 'guest_deposits');
            $running += $guest;
        }

        if (($other = $i('drawer.other_cash')) !== 0) {
            $w[] = self::line('Plus other cash', $other, style: 'indent', drill: 'other_cash');
            $running += $other;
        }

        $expenses = $i('cash.expenses');
        $remittance = $i('cash.remittance');
        $w[] = self::line('Less expenses', -$expenses, $i('drawer.counts.expenses'), 'indent', drill: 'expenses');
        $w[] = self::line('Less remittance', -$remittance, $i('drawer.counts.remittance'), 'indent', drill: 'remittance');
        $running -= $expenses + $remittance;

        if (($shortage = $i('cash.shortage_payments')) !== 0) {
            $w[] = self::line('Plus shortage payments', $shortage, $i('drawer.counts.shortage_payments'), 'indent', drill: 'shortage_payments');
            $running += $shortage;
        }

        $fromShift = $running;
        $w[] = self::line($isDay ? 'Cash from this day' : 'Cash from this shift', $fromShift, style: 'equals');

        $opening = $i('cash.opening');
        $w[] = self::line('Plus opening cash', $opening, style: 'indent');
        $expected = $meta['expected'];

        if ($expected !== null) {
            $equals('Expected in the drawer', $expected, $fromShift + $opening);
        }

        $w[] = self::line('Expected in the drawer', $expected ?? $fromShift + $opening, style: 'equals');

        $counted = $meta['counted'];
        $w[] = ['label' => 'Counted', 'amount' => $counted, 'style' => 'plain'];

        $note = $meta['variance_note'];
        if ($counted !== null && $expected !== null) {
            if ($isDay) {
                $equals('Short and over', $counted - $expected, $meta['over'] - $meta['short']);
                $w[] = ['label' => 'Short', 'amount' => -$meta['short'], 'style' => 'plain', 'tone' => $meta['short'] > 0 ? 'bad' : null];
                $w[] = ['label' => 'Over', 'amount' => $meta['over'], 'style' => 'plain'];
            } else {
                $variance = $counted - $expected;
                $w[] = [
                    'label' => $variance < 0 ? 'Short' : ($variance > 0 ? 'Over' : 'Short or over'),
                    'amount' => $variance,
                    'style' => 'plain',
                    'tone' => $variance < 0 ? 'bad' : null,
                    'note' => $note === null || $note === '' ? null : "\"{$note}\"",
                ];
            }
        }

        $sections = [[
            'key' => 'sales_cash', 'title' => 'Sales and cash', 'layout' => 'waterfall', 'column' => 'left', 'lines' => $w,
        ]];

        // ── By front desk ───────────────────────────────────────────────────────────
        $sections[] = [
            'key' => 'by_user', 'title' => 'By front desk', 'layout' => 'table', 'column' => 'right',
            'columns' => [['label' => 'Name'], ['label' => 'In', 'type' => 'count'], ['label' => 'Cash', 'type' => 'money'], ['label' => 'Back', 'type' => 'money']],
            'lines' => array_values(array_map(static fn (mixed $u): array => [
                'label' => self::shortName(is_array($u) ? (string) ($u['name'] ?? '') : ''),
                'cells' => [(int) ($u['checkins'] ?? 0), (int) ($u['cash'] ?? 0), (int) ($u['given_back'] ?? 0)],
            ], Totals::list($t, 'by_user'))),
        ];

        // ── Exceptions: fixed rows, printed even at zero ────────────────────────────────
        $ex = fn (string $key, string $label, bool $money = true): array => [
            'label' => $label,
            'count' => $i("exceptions.{$key}.count"),
            'amount' => $money ? $i("exceptions.{$key}.amount") : null,
            'style' => 'plain',
        ];
        $exceptions = [
            $ex('cancelled_stays', 'Cancelled stays'),
            $ex('voided_lines', 'Voided lines'),
            $ex('cash_refunds', 'Cash refunds'),
            $ex('transfers', 'Room transfers'),
            $ex('forfeited_deposits', 'Forfeited deposits'),
            $ex('voided_cash_movements', 'Voided cash entries'),
            $ex('reprints', 'Slip reprints', false),
        ];
        $forced = Totals::get($t, 'exceptions.force_closed');
        if ($forced === true || (is_int($forced) && $forced > 0)) {
            $exceptions[] = ['label' => 'Force closed', 'count' => is_int($forced) ? $forced : 1, 'amount' => null, 'style' => 'plain', 'tone' => 'bad'];
        }
        $sections[] = [
            'key' => 'exceptions', 'title' => 'Exceptions', 'layout' => 'table', 'column' => 'right',
            'columns' => [['label' => ''], ['label' => 'Count', 'type' => 'count'], ['label' => 'Amount', 'type' => 'money']],
            'lines' => $exceptions,
        ];

        // ── Documents ─────────────────────────────────────────────────────────────────
        $sections[] = [
            'key' => 'documents', 'title' => 'Documents', 'layout' => 'lines', 'column' => 'right',
            'lines' => [
                ['label' => 'Stays '.self::range(Totals::get($t, 'documents.stays.first'), Totals::get($t, 'documents.stays.last')), 'count' => $i('documents.stays.count'), 'style' => 'plain'],
                ['label' => 'POS orders '.self::range(Totals::get($t, 'documents.pos_orders.first'), Totals::get($t, 'documents.pos_orders.last')), 'count' => $i('documents.pos_orders.count'), 'style' => 'plain'],
            ],
        ];

        // ── Running grand total ───────────────────────────────────────────────────────
        $before = $meta['grand_total_before'];
        $after = $meta['grand_total_after'];
        $equals('After this '.($isDay ? 'day' : 'shift'), $after, $before + $net);
        $sections[] = [
            'key' => 'grand_total', 'title' => 'Running grand total', 'layout' => 'lines', 'column' => 'right',
            'lines' => [
                self::line($isDay ? 'Before this day' : 'Before this shift', $before),
                self::line($isDay ? 'This day, net sales' : 'This shift, net sales', $net),
                self::line($isDay ? 'After this day' : 'After this shift', $after, style: 'equals'),
            ],
        ];

        // ── VAT, only when the branch is VAT-registered ──────────────────────────────────
        $vat = Totals::get($t, 'vat');
        if (is_array($vat)) {
            $vatable = (int) ($vat['vatable'] ?? 0);
            $tax = (int) ($vat['vat'] ?? 0);
            $exempt = (int) ($vat['exempt'] ?? 0);
            $equals('VAT block', $net, $vatable + $tax + $exempt);
            $sections[] = [
                'key' => 'vat', 'title' => 'VAT', 'layout' => 'lines', 'column' => 'right',
                'lines' => [
                    self::line('VATable sales', $vatable),
                    self::line('VAT '.(int) ($vat['rate'] ?? 0).'%', $tax),
                    self::line('VAT-exempt sales', $exempt),
                ],
            ];
        }

        return [
            'kind' => $meta['kind'],
            'preview' => $meta['preview'],
            'header' => [
                'branch' => $meta['branch'],
                'title' => $meta['title'],
                'number' => $meta['preview'] ? null : $meta['number'],
                'opened' => $meta['opened'],
                'closed' => $meta['closed'],
                'printed' => $meta['printed'],
                'tin' => is_array($vat) ? $meta['tin'] : null,
                'registered_name' => is_array($vat) ? $meta['registered_name'] : null,
            ],
            'sections' => $sections,
            'signatures' => $meta['signatures'],
            'checks' => $checks,
            'figures' => [
                'gross_sales' => $gross,
                'net_sales' => $net,
                'collected' => $collected,
                'cash_collected' => $cashCollected,
                'cash_from_shift' => $fromShift,
                'expected' => $expected ?? $fromShift + $opening,
                'counted' => $counted,
                'short' => $isDay ? $meta['short'] : max(0, ($expected ?? 0) - ($counted ?? $expected ?? 0)),
                'over' => $isDay ? $meta['over'] : max(0, ($counted ?? $expected ?? 0) - ($expected ?? 0)),
                'grand_total_before' => $before,
                'grand_total_after' => $after,
            ],
        ];
    }

    /**
     * The 80 mm form of the shift paper (slip 26), from the same totals and the same frozen
     * expected, counted and variance. Amounts in centavos; the page only formats them.
     *
     * @param  array<array-key, mixed>  $t
     * @return list<list<array{label: string, amount?: int, text?: string, bold?: bool}>>
     */
    public static function slip(array $t, ?int $expected, ?int $counted): array
    {
        $i = fn (string $path): int => Totals::int($t, $path);

        $sales = [];
        foreach (['room' => 'Room', 'extension' => 'Extension', 'transfer_difference' => 'Transfer diff.', 'food_drink' => 'Food & drinks', 'amenity' => 'Amenities', 'damage' => 'Damages', 'forfeited_deposit' => 'Forfeited dep.'] as $key => $label) {
            $amount = $key === 'room' ? $i('sales.room') + $i('sales.discount') : $i("sales.{$key}");

            if ($amount !== 0 || $key !== 'forfeited_deposit') {
                $sales[] = ['label' => $label, 'amount' => $amount];
            }
        }
        $sales[] = ['label' => 'SALES', 'amount' => $i('sales.total'), 'bold' => true];
        $sales[] = ['label' => 'Unpaid fwd out', 'amount' => -$i('unpaid.forwarded_out')];
        $sales[] = ['label' => 'Paid fwd in', 'amount' => $i('unpaid.forwarded_in')];
        $sales[] = ['label' => 'COLLECTED', 'amount' => $i('unpaid.collected'), 'bold' => true];

        $nonCash = $i('drawer.non_cash.gcash') + $i('drawer.non_cash.card') + $i('drawer.non_cash.deposit');
        $cash = [['label' => 'Opening cash', 'amount' => $i('cash.opening')]];
        if ($nonCash !== 0) {
            $cash[] = ['label' => 'Not cash', 'amount' => -$nonCash];
        }
        $cash[] = ['label' => 'Deposits in', 'amount' => $i('drawer.deposits_taken.amount')];
        $cash[] = ['label' => 'Deposits out', 'amount' => -$i('drawer.deposits_returned.amount')];
        if ($i('drawer.guest_deposits.amount') !== 0) {
            $cash[] = ['label' => 'Guest dep. net', 'amount' => $i('drawer.guest_deposits.amount')];
        }
        if ($i('drawer.other_cash') !== 0) {
            $cash[] = ['label' => 'Other cash', 'amount' => $i('drawer.other_cash')];
        }
        $cash[] = ['label' => 'Expenses', 'amount' => -$i('cash.expenses')];
        $cash[] = ['label' => 'Remittance', 'amount' => -$i('cash.remittance')];
        if ($i('cash.shortage_payments') !== 0) {
            $cash[] = ['label' => 'Shortage paid', 'amount' => $i('cash.shortage_payments')];
        }

        $variance = ($counted ?? 0) - ($expected ?? 0);
        $result = [
            ['label' => 'Expected', 'amount' => $expected ?? 0],
            ['label' => 'Counted', 'amount' => $counted ?? 0],
            ['label' => $variance < 0 ? 'SHORT' : ($variance > 0 ? 'OVER' : 'SHORT / OVER'), 'amount' => $variance, 'bold' => true],
        ];

        $guests = [
            ['label' => 'Guests fwd in', 'text' => (string) $i('guests.forwarded_in')],
            ['label' => 'Guests fwd out', 'text' => (string) $i('guests.forwarded_out')],
            ['label' => 'Deposits held', 'amount' => $i('deposits.held_close')],
        ];

        return [$sales, $cash, $result, $guests];
    }

    /**
     * The CSV rows of a paper: Section, Line, Count, Amount (pesos with two decimals, no ₱, no
     * separators). Header facts come first. A table row with several money cells becomes one
     * row per cell.
     *
     * @param  array<string, mixed>  $paper
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function csvRows(array $paper): array
    {
        /** @var array<string, string|null> $h */
        $h = $paper['header'];
        $rows = [['Section', 'Line', 'Count', 'Amount']];

        foreach (['branch' => 'Branch', 'title' => 'Report', 'number' => 'Number', 'opened' => 'Opened', 'closed' => 'Closed', 'printed' => 'Printed', 'tin' => 'TIN', 'registered_name' => 'Registered name'] as $key => $label) {
            if (($h[$key] ?? null) !== null) {
                $rows[] = ['Header', $label.': '.$h[$key], '', ''];
            }
        }

        /** @var list<array<string, mixed>> $sections */
        $sections = $paper['sections'];
        foreach ($sections as $section) {
            /** @var list<array<string, mixed>> $lines */
            $lines = $section['lines'];

            foreach ($lines as $line) {
                if (isset($line['cells']) && is_array($line['cells'])) {
                    /** @var list<array{label: string, type?: string}> $columns */
                    $columns = $section['columns'];
                    $count = '';

                    foreach ($line['cells'] as $n => $cell) {
                        $type = $columns[$n + 1]['type'] ?? null;

                        if ($type === 'count') {
                            $count = (string) $cell;
                        }
                    }

                    foreach ($line['cells'] as $n => $cell) {
                        if (($columns[$n + 1]['type'] ?? null) === 'money') {
                            $rows[] = [(string) $section['title'], $line['label'].' · '.$columns[$n + 1]['label'], $count, self::csvAmount((int) $cell)];
                        }
                    }

                    continue;
                }

                $rows[] = [
                    (string) $section['title'],
                    (string) $line['label'],
                    isset($line['count']) ? (string) $line['count'] : '',
                    array_key_exists('amount', $line) && $line['amount'] !== null ? self::csvAmount((int) $line['amount']) : '',
                ];
            }
        }

        /** @var list<array{name: string|null, role: string}> $signatures */
        $signatures = $paper['signatures'];
        foreach ($signatures as $s) {
            $rows[] = ['Signatures', $s['role'].': '.($s['name'] ?? ''), '', ''];
        }

        return $rows;
    }

    public static function csvAmount(int $centavos): string
    {
        $sign = $centavos < 0 ? '-' : '';
        $abs = abs($centavos);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "Jessa Mae Dela Cruz" → "Jessa Mae D.", "Rhea Bautista" → "Rhea B." (as shortName() in Vue). */
    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        if (count($parts) > 2) {
            return "{$parts[0]} {$parts[1]} ".mb_substr($parts[2], 0, 1).'.';
        }

        return count($parts) === 2 ? "{$parts[0]} ".mb_substr($parts[1], 0, 1).'.' : ($parts[0] ?? '');
    }

    /** "DGS-26-004112 to 004138": the prefix once (DESIGN_SYSTEM §7). */
    public static function range(mixed $first, mixed $last): string
    {
        if (! is_string($first) || $first === '') {
            return '—';
        }

        if (! is_string($last) || $last === '' || $last === $first) {
            return $first;
        }

        $cut = strrpos($last, '-');

        return $first.' to '.($cut === false ? $last : substr($last, $cut + 1));
    }

    /** @return array{label: string, amount: int, count?: int, style: string, drill?: string} */
    private static function line(string $label, int $amount, ?int $count = null, string $style = 'plain', ?string $drill = null): array
    {
        $line = ['label' => $label, 'amount' => $amount, 'style' => $style];

        if ($count !== null) {
            $line['count'] = $count;
        }

        if ($drill !== null) {
            $line['drill'] = $drill;
        }

        return $line;
    }

    private static function pesos(int $centavos): string
    {
        return '₱'.number_format($centavos / 100, 2);
    }
}
