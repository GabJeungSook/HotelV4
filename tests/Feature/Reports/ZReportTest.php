<?php

declare(strict_types=1);

use App\Models\ShiftSession;
use App\Services\Reports\ZPaper;
use App\Services\ReportService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoShiftSeeder;

/*
| The Z-report builder array (37b, TRD §4.10) on the seeded demo shift, line by line against
| mockup 37b, with every bold line equal to the arithmetic of the lines above it. The screen, the
| slip, the PDF and the CSV render this one array (ExportsTest).
*/

beforeEach(function (): void {
    $this->seed([DatabaseSeeder::class, DemoShiftSeeder::class]);
    $this->am = ShiftSession::query()->where('z_no', 629)->sole();
    $this->paper = app(ReportService::class)->zReport($this->am);
    $this->section = fn (string $key): array => collect($this->paper['sections'])->firstWhere('key', $key);
});

it('prints the header of Z-0629 as in mockup 37b', function (): void {
    expect($this->paper['preview'])->toBeFalse()
        ->and($this->paper['header'])->toBe([
            'branch' => 'Hotel Dalisay GenSan',
            'title' => 'Z-READ · AM shift · Wed 11 Nov 2026',
            'number' => 'Z-0629',
            'opened' => 'Opened 7:58 AM',
            'closed' => 'Closed 8:04 PM',
            'printed' => 'Printed 8:06 PM by Jessa Mae D.',
            'tin' => null,
            'registered_name' => null,
        ]);
});

it('walks the sales-and-cash column of 37b line by line, from gross sales to short', function (): void {
    $lines = array_map(fn (array $l): array => [$l['label'], $l['count'] ?? null, $l['amount'], $l['style']], ($this->section)('sales_cash')['lines']);

    expect($lines)->toBe([
        ['Gross sales', null, 1846000, 'plain'],
        ['Less Promo ₱50', 4, -20000, 'indent'],
        ['Less Senior citizen 20%', 1, -10000, 'indent'],
        ['Net sales', null, 1816000, 'equals'],
        ['Less unpaid, forwarded out', 3, -34000, 'indent'],
        ['Plus paid for earlier shifts', 2, 22500, 'indent'],
        ['Collected', null, 1804500, 'equals'],
        ['Less GCash', 0, 0, 'indent'],
        ['Less card', 0, 0, 'indent'],
        ['Less paid from deposit', 0, 0, 'indent'],
        ['Cash collected', null, 1804500, 'equals'],
        ['Plus deposits taken', 27, 540000, 'indent'],
        ['Less deposits returned', 23, -460000, 'indent'],
        ['Less expenses', 3, -65000, 'indent'],
        ['Less remittance', 1, -1000000, 'indent'],
        ['Cash from this shift', null, 819500, 'equals'],
        ['Plus opening cash', null, 500000, 'indent'],
        ['Expected in the drawer', null, 1319500, 'equals'],
        ['Counted', null, 1314500, 'plain'],
        ['Short', null, -5000, 'plain'],
    ])
        ->and(($this->section)('sales_cash')['lines'][19]['note'])->toBe('"Gave wrong change to Room 203, ₱50."')
        ->and(($this->section)('sales_cash')['lines'][19]['tone'])->toBe('bad');
});

it('has every bold line equal to the arithmetic of the lines above it (no failed self-check)', function (): void {
    $lines = ($this->section)('sales_cash')['lines'];
    $running = 0;

    foreach ($lines as $line) {
        if ($line['label'] === 'Counted') {
            break;
        }

        if ($line['style'] === 'equals') {
            expect($line['amount'])->toBe($running, $line['label']);
        } else {
            $running += $line['amount'];
        }
    }

    expect($this->paper['checks'])->toBe([])
        ->and($this->paper['figures']['expected'])->toBe($this->am->expected_cash->centavos())
        ->and($this->paper['figures']['net_sales'])->toBe($this->am->totals['sales']['total'])
        ->and($this->paper['figures']['grand_total_after'])->toBe($this->am->grand_total_before->centavos() + $this->paper['figures']['net_sales']);
});

it('has the right-hand panels: by front desk, the fixed exceptions, documents and the grand total', function (): void {
    expect(array_map(fn (array $l): array => [$l['label'], ...$l['cells']], ($this->section)('by_user')['lines']))->toBe([
        ['Jessa Mae D.', 15, 1048000, 0],
        ['Rhea B.', 12, 756500, 70000],
    ])
        ->and(array_map(fn (array $l): array => [$l['label'], $l['count'], $l['amount']], ($this->section)('exceptions')['lines']))->toBe([
            ['Cancelled stays', 1, 70000],
            ['Voided lines', 0, 0],
            ['Cash refunds', 1, 70000],
            ['Room transfers', 1, 15000],
            ['Forfeited deposits', 0, 0],
            ['Voided cash entries', 1, 300000],
            ['Slip reprints', 2, null],
        ])
        ->and(array_map(fn (array $l): array => [$l['label'], $l['count']], ($this->section)('documents')['lines']))->toBe([
            ['Stays DGS-26-004112 to 004138', 27],
            ['POS orders P-008821 to 008861', 41],
        ])
        ->and(array_map(fn (array $l): array => [$l['label'], $l['amount'], $l['style']], ($this->section)('grand_total')['lines']))->toBe([
            ['Before this shift', 1148234000, 'plain'],
            ['This shift, net sales', 1816000, 'plain'],
            ['After this shift', 1150050000, 'equals'],
        ])
        ->and(collect($this->paper['sections'])->pluck('key')->all())->not->toContain('vat')
        ->and($this->paper['signatures'])->toBe([
            ['name' => 'Jessa Mae Dela Cruz', 'role' => 'Closed by'],
            ['name' => 'Mark Anthony Sumagaysay', 'role' => 'Received by'],
            ['name' => null, 'role' => 'Back office · name and date'],
        ]);
});

it('prints the 80 mm slip (26) from the same totals as the paper', function (): void {
    $slip = app(ReportService::class)->slip($this->am);
    $flat = array_merge(...$slip);
    $value = fn (string $label): mixed => collect($flat)->firstWhere('label', $label)['amount'] ?? collect($flat)->firstWhere('label', $label)['text'];

    expect($value('Room'))->toBe(1435000)
        ->and($value('SALES'))->toBe($this->paper['figures']['net_sales'])
        ->and($value('COLLECTED'))->toBe($this->paper['figures']['collected'])
        ->and($value('Expected'))->toBe($this->paper['figures']['expected'])
        ->and($value('Counted'))->toBe(1314500)
        ->and($value('SHORT'))->toBe(-5000)
        ->and($value('Guests fwd in'))->toBe('19')
        ->and($value('Guests fwd out'))->toBe('23')
        ->and($value('Deposits held'))->toBe(460000);
});

it('writes CSV rows that carry every line of the paper', function (): void {
    $rows = ZPaper::csvRows($this->paper);
    $find = fn (string $line): ?array => collect($rows)->first(fn (array $r): bool => $r[1] === $line);

    expect($rows[0])->toBe(['Section', 'Line', 'Count', 'Amount'])
        ->and($find('Net sales'))->toBe(['Sales and cash', 'Net sales', '', '18160.00'])
        ->and($find('Expected in the drawer'))->toBe(['Sales and cash', 'Expected in the drawer', '', '13195.00'])
        ->and($find('Short'))->toBe(['Sales and cash', 'Short', '', '-50.00'])
        ->and($find('After this shift'))->toBe(['Running grand total', 'After this shift', '', '11500500.00'])
        ->and($find('Rhea B. · Back'))->toBe(['By front desk', 'Rhea B. · Back', '12', '700.00'])
        ->and($find('Slip reprints'))->toBe(['Exceptions', 'Slip reprints', '2', '']);
});
