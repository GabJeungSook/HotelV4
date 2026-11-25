<?php

declare(strict_types=1);

use App\Actions\Shifts\CloseShift;
use App\Exceptions\ActionRefused;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\Hotel;

/*
| The blind count in total mode — the branch default (branches.denomination_count_enabled = false,
| TRD §4.12). The front desk types one counted total instead of the pieces of each bill; the
| cash_counts row holds that total and no denominations.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 11:00:00');
    $this->branch = Hotel::branch();
    $this->jessa = Hotel::frontdesk();
    Hotel::openShift($this->jessa);
});

it('is off by default, so a branch counts by a single total', function (): void {
    expect($this->branch->refresh()->denomination_count_enabled)->toBeFalse();
});

it('saves the typed total as the counted cash, with no denominations', function (): void {
    $close = app(CloseShift::class);
    $close->begin($this->jessa, $this->branch->id);

    $count = $close->count($this->jessa, $this->branch->id, [], total: Money::ofPesos(5000));

    expect($count->total_cash?->centavos())->toBe(500000)
        ->and($count->denominations)->toBe([])
        ->and($count->counted_by)->toBe($this->jessa->id);
});

it('refuses a total-mode count with no total typed', function (): void {
    $close = app(CloseShift::class);
    $close->begin($this->jessa, $this->branch->id);

    expect(fn () => $close->count($this->jessa, $this->branch->id, ['coins' => 999], total: null))
        ->toThrow(ActionRefused::class);
});

it('uses the total and ignores any pieces sent in total mode', function (): void {
    $close = app(CloseShift::class);
    $close->begin($this->jessa, $this->branch->id);

    $count = $close->count($this->jessa, $this->branch->id, ['100000' => 9], total: Money::ofPesos(1000));

    expect($count->total_cash?->centavos())->toBe(100000) // the ₱1,000 total, not 9 × ₱1,000 of pieces
        ->and($count->denominations)->toBe([]);
});
