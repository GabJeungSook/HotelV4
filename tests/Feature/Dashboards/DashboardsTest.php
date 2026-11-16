<?php

declare(strict_types=1);

use App\Actions\Stays\AddCharges;
use App\Actions\Stays\TakePayment;
use App\Enums\PaymentMethod;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\User;
use App\Queries\StayQuery;
use App\Services\Dashboard\Period;
use App\Services\DashboardService;
use App\Summaries\RebuildSummaries;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| TRD §10 "Dashboards and summaries": same-time comparison for today · no change figure without
| previous data · short and over not netted · front desk props never contain expected cash or
| collected amounts · owner sees all branches, admin only their own · query budget per card.
| ROADMAP Phase 9 "Done when": a payment taken at the front desk shows on the branch and owner
| dashboards without a page reload (the 30 s partial reload of the deferred groups).
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-10 02:00:00'); // 10:00 AM Manila
});

/** Write summary rows for a branch-day directly (the dashboards only read these tables). */
function summaryDay(int $branchId, string $date, array $daily = [], array $hours = []): void
{
    DB::table('daily_summaries')->insert(array_merge([
        'branch_id' => $branchId, 'business_date' => $date, 'gross_sales_amount' => 0, 'discount_amount' => 0, 'net_sales_amount' => 0,
        'room_sales_amount' => 0, 'other_sales_amount' => 0, 'collected_amount' => 0, 'refund_amount' => 0, 'short_amount' => 0,
        'over_amount' => 0, 'checkins' => 0, 'checkouts' => 0, 'stays_in_house_end' => 0, 'room_count' => 10, 'cleans' => 0, 'late_cleans' => 0,
    ], $daily));

    foreach ($hours as $hour => $amount) {
        DB::table('hourly_sales_summaries')->insert(['branch_id' => $branchId, 'business_date' => $date, 'local_date' => $date, 'hour' => $hour, 'net_sales_amount' => $amount, 'checkins' => 1]);
    }
}

function dash(): DashboardService
{
    return app(DashboardService::class);
}

it('compares today with yesterday up to the same time of day', function (): void {
    $branch = Hotel::branch()->id;
    // Yesterday: ₱1,000 by 10 AM and ₱9,000 more later in the day.
    summaryDay($branch, '2026-11-09', ['net_sales_amount' => 1000000], [8 => 50000, 10 => 50000, 15 => 900000]);
    summaryDay($branch, '2026-11-10', ['net_sales_amount' => 120000], [8 => 60000, 9 => 60000]);

    $totals = dash()->totals([$branch => Period::of('today', '2026-11-10')]);

    // 1,200 against yesterday's 1,000 by this time: +20%, never against the whole 10,000.
    expect($totals['sales'])->toBe(120000)
        ->and($totals['change'])->toBe(20)
        ->and($totals['compared_with'])->toBe('vs yesterday by this time');
});

it('shows no change figure without previous data', function (): void {
    $branch = Hotel::branch()->id;
    summaryDay($branch, '2026-11-10', ['net_sales_amount' => 120000], [9 => 120000]);

    expect(dash()->totals([$branch => Period::of('today', '2026-11-10')])['change'])->toBeNull()
        ->and(dash()->totals([$branch => Period::of('week', '2026-11-10')])['change'])->toBeNull()
        ->and(DashboardService::change(500, 0))->toBeNull();
});

it('compares longer periods with the same-length period just before', function (): void {
    $branch = Hotel::branch()->id;
    summaryDay($branch, '2026-11-08', ['net_sales_amount' => 100000]); // Sun, the week before
    summaryDay($branch, '2026-11-09', ['net_sales_amount' => 150000]); // Mon
    summaryDay($branch, '2026-11-10', ['net_sales_amount' => 150000]); // Tue

    $period = Period::of('week', '2026-11-10');

    expect([$period->from, $period->to, $period->previousFrom, $period->previousTo])->toBe(['2026-11-09', '2026-11-10', '2026-11-07', '2026-11-08'])
        ->and(dash()->totals([$branch => $period])['change'])->toBe(200);
});

it('sums short and over separately, never netted', function (): void {
    $branch = Hotel::branch()->id;
    summaryDay($branch, '2026-11-09', ['short_amount' => 8000, 'over_amount' => 3000]);
    summaryDay($branch, '2026-11-10', ['short_amount' => 2000, 'over_amount' => 0]);

    $totals = dash()->totals([$branch => Period::of('week', '2026-11-10')]);

    expect($totals['short'])->toBe(10000)->and($totals['over'])->toBe(3000);
});

it('never sends expected cash or collected amounts to the front desk', function (): void {
    Hotel::openShift();
    Hotel::checkIn('101', 6);
    $fd = Hotel::frontdesk();

    foreach (['/dashboard', '/owner', '/stats', '/admin/exceptions', '/owner/summary'] as $url) {
        $this->actingAs($fd)->get($url)->assertForbidden();
    }

    expect(dash()->openShift(Hotel::branch()->id, $fd))->toBeNull();

    $props = json_encode($this->actingAs($fd)->get('/today')->viewData('page')['props']);
    expect($props)->not->toContain('expected_cash')->not->toContain('collected');

    // Managers get the open-shift block with each member's collections.
    $block = dash()->openShift(Hotel::branch()->id, Hotel::user('ramil.ortega'));
    expect($block['members'][0]['collected'])->toBeGreaterThan(0);
});

it('shows the owner every branch and an admin only their own', function (): void {
    $second = Branch::factory()->create(['name' => 'Hotel Sample Polomolok', 'code_prefix' => 'SPL']);
    $otherAdmin = User::factory()->admin()->create(['branch_id' => $second->id]);
    summaryDay(Hotel::branch()->id, '2026-11-10', ['net_sales_amount' => 300000]);
    summaryDay($second->id, '2026-11-10', ['net_sales_amount' => 50000]);

    $this->actingAs(Hotel::user('ramon.alvarez'))->get('/owner')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Owner')->has('branches', 2)
            ->loadDeferredProps(['kpis', 'now'], fn (Assert $reload) => $reload
                ->where('kpis.totals.sales', 350000)
                ->has('now.branches', 2)));

    $this->actingAs($otherAdmin)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Branch')->where('branch.id', $second->id)
            ->loadDeferredProps('kpis', fn (Assert $reload) => $reload->where('kpis.totals.sales', 50000)));

    $this->actingAs($otherAdmin)->get('/stats?from=2026-11-01&to=2026-11-10')
        ->assertInertia(fn (Assert $page) => $page->where('branch', $second->id)
            ->loadDeferredProps('cards', fn (Assert $reload) => $reload->where('totals.sales', 50000)));

    // An admin's ?branch= is ignored.
    $this->actingAs($otherAdmin)->get('/stats?from=2026-11-01&to=2026-11-10&branch='.Hotel::branch()->id)
        ->assertInertia(fn (Assert $page) => $page->where('branch', $second->id));
});

it('keeps each dashboard card within three queries', function (): void {
    Hotel::openShift();
    Hotel::checkIn('101', 6);
    app(RebuildSummaries::class)->run();
    $branch = Hotel::branch()->id;
    $periods = dash()->periods([$branch], 'today');
    $admin = Hotel::user('ramil.ortega');

    $cards = [
        'totals' => fn () => dash()->totals($periods),
        'rooms' => fn () => dash()->roomsNow([$branch]),
        'unpaid' => fn () => dash()->unpaid($branch),
        'sales by hour' => fn () => dash()->salesByHour($periods, 8),
        'last 7 days' => fn () => dash()->lastSevenDays($periods),
        'payments' => fn () => dash()->paymentsByMethod($periods, ['cash']),
        'open shift' => fn () => dash()->openShift($branch, $admin),
        'housekeeping' => fn () => dash()->housekeeping($branch, $periods),
    ];

    foreach ($cards as $name => $card) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = microtime(true);
        $card();
        $time = microtime(true) - $start;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($queries)->toBeLessThanOrEqual(3, "{$name}: {$queries} queries")
            ->and($time)->toBeLessThan(0.3);
    }
});

it('never sums folio_lines or payments over a period on a dashboard request', function (): void {
    Hotel::openShift();
    Hotel::checkIn('101', 6);
    app(RebuildSummaries::class)->run();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs(Hotel::user('ramil.ortega'))->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(['kpis', 'charts', 'attention'], fn (Assert $r) => $r->has('kpis')));
    $sql = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q): bool => preg_match('/from [`"]?(folio_lines|payments)[`"]?/i', $q) === 1);
    DB::disableQueryLog();

    // The only ledger read is the unpaid balance of the guests in house now (bounded live state).
    expect($sql->every(fn (string $q): bool => str_contains($q, 'stays')))->toBeTrue();
});

it('shows a front desk payment on the branch and owner dashboards after the next rebuild, without a page reload', function (): void {
    Hotel::openShift();
    $stay = Hotel::checkIn('205', 12);
    app(AddCharges::class)->handle(Hotel::frontdesk(), $stay, [Hotel::product('Chips')->id => 2]);
    app(RebuildSummaries::class)->run();

    $admin = Hotel::user('ramil.ortega');
    $owner = Hotel::user('ramon.alvarez');
    $gcash = fn (User $u, string $url) => collect($this->actingAs($u)->get($url, [
        'X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? '',
        'X-Inertia-Partial-Component' => $url === '/owner' ? 'Dashboard/Owner' : 'Dashboard/Branch',
        'X-Inertia-Partial-Data' => $url === '/owner' ? 'charts' : 'shift',
    ])->json('props'));

    $before = $gcash($owner, '/owner')['charts']['methods'];
    expect(collect($before)->firstWhere('method', 'gcash')['amount'] ?? 0)->toBe(0);

    // The front desk takes the balance by GCash; one minute later the rebuild runs.
    $due = app(StayQuery::class)->balanceDue($stay->id);
    app(TakePayment::class)->handle(Hotel::frontdesk(), $stay, PaymentMethod::Gcash, $due, referenceNo: '0912345678');
    $this->travelClock(1);
    app(RebuildSummaries::class)->run();

    // The 30 s partial reload of the same page now carries it.
    expect(collect($gcash($owner, '/owner')['charts']['methods'])->firstWhere('method', 'gcash')['amount'])->toBe($due->centavos());
    expect($gcash($admin, '/dashboard')['shift']['shift']['members'][0]['collected'])->toBeGreaterThanOrEqual($due->centavos());
});
