<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use App\Services\ReportService;
use App\Support\Clock;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\Hotel;

/*
| Owner consolidated summary (39) + CSV export: sales/cash/occupancy per branch and an All total,
| built from closed shifts' frozen totals (ReportService::summary), downloadable as CSV.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 10:00:00');
    Hotel::openShift();
    Hotel::checkIn('101', 6);
    Hotel::closeAndHandOver(Hotel::user('mark.sumagaysay'));
    $this->date = app(Clock::class)->localDate();
});

it('consolidates sales per branch with an All total', function (): void {
    $summary = app(ReportService::class)->summary([Hotel::branch()->id], $this->date, $this->date);

    expect($summary['rows'])->not->toBeEmpty()
        ->and($summary['rows'][0])->toHaveKeys(['name', 'sales', 'cash', 'net', 'checkins', 'short', 'over'])
        ->and($summary['total']['sales'])->toBeGreaterThan(0)
        ->and($summary['total']['name'])->toBe('All');
});

it('lets the owner export the consolidated summary as CSV', function (): void {
    $owner = User::query()->withoutGlobalScopes()->where('role', Role::Owner)->firstOrFail();

    $res = $this->actingAs($owner)->get(route('owner.summary', ['date' => $this->date, 'range' => 'week', 'export' => 'csv']));

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');
});
