<?php

declare(strict_types=1);

use App\Models\PrintLog;
use App\Services\StatsService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Stats (63, TRD §4.11): grain by range, comparison with the same-length period before (none
| without earlier data), summary tables only, a year within 1 s (TRD §7), CSV export, owner
| summary (39) page.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-10 02:00:00');
});

/** A generated year of summary rows for the branch. */
function summaryYear(int $branchId): void
{
    $daily = [];
    $hourly = [];
    $lines = [];
    for ($d = CarbonImmutable::parse('2025-11-11'); $d->lessThanOrEqualTo(CarbonImmutable::parse('2026-11-10')); $d = $d->addDay()) {
        $date = $d->toDateString();
        $daily[] = ['branch_id' => $branchId, 'business_date' => $date, 'gross_sales_amount' => 1200000, 'discount_amount' => 0, 'net_sales_amount' => 1200000, 'room_sales_amount' => 1000000,
            'other_sales_amount' => 200000, 'collected_amount' => 1200000, 'refund_amount' => 0, 'short_amount' => 0, 'over_amount' => 0, 'checkins' => 20, 'checkouts' => 20,
            'stays_in_house_end' => 10, 'room_count' => 40, 'cleans' => 18, 'late_cleans' => 1];
        foreach ([9, 14, 21] as $h) {
            $hourly[] = ['branch_id' => $branchId, 'business_date' => $date, 'local_date' => $date, 'hour' => $h, 'net_sales_amount' => 400000, 'checkins' => $h === 21 ? 10 : 5];
        }
        foreach ([['room_type', 'Standard', 12, 600000], ['room_type', 'Deluxe', 8, 400000], ['stay_option', '6 hrs', 12, 500000], ['stay_option', 'Overnight', 8, 500000], ['product', 'Chips', 10, 100000]] as [$s, $l, $q, $a]) {
            $lines[] = ['branch_id' => $branchId, 'business_date' => $date, 'section' => $s, 'label' => $l, 'quantity' => $q, 'amount' => $a];
        }
    }
    foreach ([['daily_summaries', $daily], ['hourly_sales_summaries', $hourly], ['daily_report_lines', $lines]] as [$table, $rows]) {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}

it('picks the grain from the range', function (): void {
    expect(StatsService::grain('2026-11-10', '2026-11-10'))->toBe('hour')
        ->and(StatsService::grain('2026-11-09', '2026-11-10'))->toBe('hour')
        ->and(StatsService::grain('2026-11-08', '2026-11-10'))->toBe('day')
        ->and(StatsService::grain('2026-09-10', '2026-11-10'))->toBe('day')
        ->and(StatsService::grain('2026-09-09', '2026-11-10'))->toBe('month');
});

it('compares with the same-length period before, and not at all without earlier data', function (): void {
    $branch = Hotel::branch()->id;
    summaryYear($branch);
    $stats = app(StatsService::class);

    $t = $stats->totals([$branch], '2026-11-01', '2026-11-10');
    expect([$t['previous_from'], $t['previous_to']])->toBe(['2026-10-22', '2026-10-31'])
        ->and($t['sales'])->toBe(10 * 1200000)->and($t['change'])->toBe(0)
        ->and($t['stays'])->toBe(200)->and($t['average'])->toBe(60000)
        ->and($t['busiest_hour'])->toBe(21);

    expect($stats->totals([$branch], '2025-11-11', '2025-11-20')['change'])->toBeNull();
});

it('serves a year of Stats from the summary tables within 1 s', function (): void {
    summaryYear(Hotel::branch()->id);
    $admin = Hotel::user('ramil.ortega');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $start = microtime(true);
    $this->actingAs($admin)->get('/stats?from=2025-11-11&to=2026-11-10')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Stats/Index')->where('grain', 'month')
            ->loadDeferredProps(['cards', 'series', 'charts', 'tables'], fn (Assert $r) => $r
                ->where('totals.sales', 365 * 1200000)
                ->has('series.points', 13)
                ->where('heat.Sat.21', 52 * 10)
                ->where('chose.0.label', '6 hrs')));
    $elapsed = microtime(true) - $start;
    $ledger = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q): bool => preg_match('/from [`"]?(folio_lines|payments)[`"]?/i', $q) === 1);
    DB::disableQueryLog();

    expect($elapsed)->toBeLessThan(1.0)->and($ledger)->toBeEmpty();
});

it('exports the Stats tables as CSV and logs it', function (): void {
    summaryYear(Hotel::branch()->id);

    $response = $this->actingAs(Hotel::user('ramil.ortega'))->get('/stats?from=2026-11-01&to=2026-11-10&export=csv');

    $response->assertOk();
    expect($response->streamedContent())->toContain('Sales by day')->toContain('Standard')
        ->and(PrintLog::query()->where('format', 'csv')->exists())->toBeTrue();
});

it('shows the owner summary for all branches from the closed shifts', function (): void {
    $this->actingAs(Hotel::user('ramon.alvarez'))->get('/owner/summary?date=2026-11-09')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Owner/Summary')->where('branch', 'all')
            ->where('summary.from', '2026-11-03')->where('summary.to', '2026-11-09')->has('days', 7));

    $this->actingAs(Hotel::user('ramil.ortega'))->get('/owner/summary')->assertForbidden();
});
