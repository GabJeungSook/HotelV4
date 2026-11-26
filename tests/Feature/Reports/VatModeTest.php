<?php

declare(strict_types=1);

use App\Services\CurrentShift;
use App\Services\Reports\Totals;
use App\Services\ReportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Collection;
use Tests\Support\Hotel;

/*
| VAT on the shift totals (ReportService), in both modes. Prices are VAT-inclusive, so vatable +
| vat + exempt always equals net sales — the identity the Z-report checks (ZPaper 'VAT block').
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    Hotel::openShift();
    $this->totals = function (): array {
        $shift = app(CurrentShift::class)->find(Hotel::branch()->id);

        return app(ReportService::class)->computeShiftTotals($shift, new Collection, new Collection);
    };
});

it('splits VAT per line in percentage mode', function (): void {
    Hotel::branch()->update(['vat_enabled' => true, 'vat_mode' => 'percent', 'vat_rate_percent' => 12, 'tin' => '123', 'registered_name' => 'Co.']);
    Hotel::checkIn('101', 6);

    $t = ($this->totals)();
    $vat = $t['vat'];

    expect($vat['mode'])->toBe('percent')
        ->and($vat['rate'])->toBe(12)
        ->and($vat['vat'])->toBeGreaterThan(0)
        ->and($vat['vatable'] + $vat['vat'] + $vat['exempt'])->toBe(Totals::int($t, 'sales.total'));
});

it('charges a fixed VAT per taxable transaction in amount mode', function (): void {
    Hotel::branch()->update(['vat_enabled' => true, 'vat_mode' => 'amount', 'vat_amount' => 5000, 'tin' => '123', 'registered_name' => 'Co.']);
    Hotel::checkIn('101', 6);
    Hotel::checkIn('102', 6);

    $t = ($this->totals)();
    $vat = $t['vat'];

    // Two stays → two transactions × ₱50; the split identity still holds.
    expect($vat['mode'])->toBe('amount')
        ->and($vat['amount'])->toBe(5000)
        ->and($vat['vat'])->toBe(10000)
        ->and($vat['vatable'] + $vat['vat'] + $vat['exempt'])->toBe(Totals::int($t, 'sales.total'));
});
