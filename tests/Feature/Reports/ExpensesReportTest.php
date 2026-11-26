<?php

declare(strict_types=1);

use App\Enums\CashMovementKind;
use App\Services\ReportService;
use App\Support\Clock;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Expenses report (new): cash expenses by category over a range, with every expense listed.
| Read-only through ReportService::expenses (cash_movements, voided ones excluded).
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 10:00:00');
    Hotel::openShift();
    $this->date = app(Clock::class)->localDate();

    Hotel::movement(CashMovementKind::Expense, 50000, note: 'Water delivery', category: 'Drinking water');
    Hotel::movement(CashMovementKind::Expense, 30000, note: 'More water', category: 'Drinking water');
    Hotel::movement(CashMovementKind::Expense, 20000, note: 'Top up', category: 'Drinking water');

    $this->travelClock(30);
});

it('totals expenses by category and lists each one', function (): void {
    $report = app(ReportService::class)->expenses(Hotel::branch()->id, $this->date, 'day');

    expect($report['total'])->toBe(100000)
        ->and($report['count'])->toBe(3)
        ->and($report['rows'])->toHaveCount(3)
        ->and(collect($report['by_category'])->firstWhere('category', 'Drinking water'))
        ->toMatchArray(['category' => 'Drinking water', 'count' => 3, 'amount' => 100000]);
});

it('renders for admin and exports CSV, but not for front desk', function (): void {
    $this->actingAs(Hotel::user('ramil.ortega'))->get(route('reports.expenses', ['date' => $this->date]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Reports/Expenses')->where('report.total', 100000));

    $csv = $this->actingAs(Hotel::user('ramil.ortega'))->get(route('reports.expenses', ['date' => $this->date, 'export' => 'csv']));
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv');

    $this->actingAs(Hotel::frontdesk())->get(route('reports.expenses', ['date' => $this->date]))->assertForbidden();
});
