<?php

declare(strict_types=1);

use App\Actions\Shifts\HandOverShift;
use App\Actions\Stays\AddCharges;
use App\Enums\RoomStatus;
use App\Enums\ShiftStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Queries\BoardQuery;
use App\Services\ReportService;
use App\Services\StatsService;
use App\Summaries\RebuildSummaries;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Performance budgets (TRD §7), measured on the MySQL test database. The data is generated in
| bulk here, so this suite runs without the per-test invariant check of tests/Feature. The
| bundle budget (350 kB gzip) is checked by scripts/bundle-budget.mjs after every `npm run build`;
| the per-card dashboard query budget is in tests/Feature/Dashboards/DashboardsTest.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-10 02:00:00'); // 10:00 AM Manila
});

/**
 * Run $fn and return [seconds, queries, result].
 *
 * @return array{0: float, 1: int, 2: mixed}
 */
function measure(callable $fn): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $start = hrtime(true);
    $result = $fn();
    $seconds = (hrtime(true) - $start) / 1e9;
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    return [$seconds, $queries, $result];
}

/** With PERF_REPORT=1 in the environment, print each measured figure (for the release notes). */
function budgetNote(string $label, float|int $value): void
{
    if (getenv('PERF_REPORT')) {
        fwrite(STDERR, sprintf('  %-40s %s
', $label, is_float($value) ? round($value * 1000).' ms' : $value));
    }
}

/** Fill the demo branch up to $total rooms on extra floors of Standard rooms. */
function growTo(int $total): void
{
    $branch = Hotel::branch();
    $type = RoomType::query()->where('name', 'Standard')->firstOrFail();
    $have = Room::query()->count();
    $floorNo = 10;

    while ($have < $total) {
        $floor = Floor::query()->create(['branch_id' => $branch->id, 'number' => $floorNo, 'label' => "{$floorNo}F"]);
        $rows = [];
        for ($i = 1; $i <= 50 && $have < $total; $i++, $have++) {
            $rows[] = [
                'branch_id' => $branch->id, 'floor_id' => $floor->id, 'room_type_id' => $type->id,
                'number' => (string) ($floorNo * 100 + $i), 'area' => 'Main', 'status' => RoomStatus::Available->value,
                'is_kiosk_enabled' => true, 'is_active' => true,
            ];
        }
        Room::query()->insert($rows);
        $floorNo++;
    }
}

/** Copy every closed shift of the demo branch onto the $days dates before it (AM and PM each day). */
function yearOfShifts(int $days): void
{
    $template = ShiftSession::query()->where('status', ShiftStatus::Closed)->firstOrFail();
    $row = collect(DB::table('shift_sessions')->where('id', $template->id)->first())->except(['id', 'open_branch_id'])->all();
    $definitions = DB::table('shift_definitions')->where('branch_id', $template->branch_id)->pluck('id')->all();
    $z = (int) DB::table('shift_sessions')->max('z_no');
    $base = $template->business_date->toImmutable();

    for ($d = 1; $d <= $days; $d++) {
        $date = $base->subDays($d)->toDateString();
        $batch = [];
        foreach ($definitions as $definition) {
            $batch[] = [...$row, 'business_date' => $date, 'shift_definition_id' => $definition, 'z_no' => ++$z];
        }
        DB::table('shift_sessions')->insert($batch);
    }
}

/** Copy the summary rows of one rebuilt day onto the $days dates before it. */
function yearOfSummaries(string $date, int $days): void
{
    $branch = Hotel::branch()->id;

    foreach (['daily_summaries', 'hourly_sales_summaries', 'daily_payment_summaries', 'daily_report_lines'] as $table) {
        $rows = DB::table($table)->where('branch_id', $branch)->where('business_date', $date)->get()
            ->map(fn (object $r): array => collect($r)->except('id')->all());

        for ($d = 1; $d <= $days; $d++) {
            $day = CarbonImmutable::parse($date)->subDays($d)->toDateString();
            DB::table($table)->insert($rows->map(fn (array $r): array => [
                ...$r, 'business_date' => $day, ...(array_key_exists('local_date', $r) ? ['local_date' => $day] : []),
            ])->all());
        }
    }
}

it('meets the room board, action, handover and report budgets with 300 rooms and 300 guests in house', function (): void {
    growTo(300);
    Hotel::openShift();

    $times = [];
    foreach (Room::query()->orderBy('id')->pluck('number') as $i => $number) {
        [$t] = measure(fn () => Hotel::checkIn((string) $number, 12, guest: "Guest {$i}"));
        $times[] = $t;
    }
    $stay = Stay::query()->latest('id')->firstOrFail();
    [$charge] = measure(fn () => app(AddCharges::class)->handle(Hotel::frontdesk(), $stay, [Hotel::product('Chips')->id => 2]));

    array_shift($times); // the first one warms the framework up
    budgetNote('slowest check-in (of 299)', max($times));
    budgetNote('add charge', $charge);
    expect(max($times))->toBeLessThan(0.5, 'slowest check-in: '.round(max($times), 3).' s')
        ->and($charge)->toBeLessThan(0.5);

    // Room board (4): BoardQuery ≤ 6 queries; the whole request ≤ 800 ms; the 15 s partial reload ≤ 300 ms.
    $board = app(BoardQuery::class);
    $branchId = Hotel::branch()->id;
    [, $queries, $rows] = measure(fn () => $board->forBranch($branchId));
    budgetNote('board queries', $queries);
    expect($queries)->toBeLessThanOrEqual(6, "board: {$queries} queries")
        ->and($rows['total'])->toBe(300);

    $this->actingAs(Hotel::frontdesk());
    $this->get('/board'); // warm up the route and view
    // The board is a deferred prop: first load = the page + the board request it makes at once.
    [$page] = measure(fn () => $this->get('/board')->assertOk());
    [$partial] = measure(fn () => $this->get('/board', [
        'X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia-Partial-Component' => 'Board/Index', 'X-Inertia-Partial-Data' => 'board',
    ])->assertOk()->assertJsonPath('props.board.total', 300));
    $first = $page + $partial;
    budgetNote('board first load (page + board)', $first);
    budgetNote('board refresh (partial)', $partial);
    expect($first)->toBeLessThan(0.8, "board first load {$first} s")
        ->and($partial)->toBeLessThan(0.3, "board refresh {$partial} s");

    // RebuildSummaries for this busy branch-day ≤ 2 s.
    [$rebuild] = measure(fn () => app(RebuildSummaries::class)->day(Hotel::branch()->id, '2026-11-10'));
    budgetNote('RebuildSummaries, 300-stay day', $rebuild);
    expect($rebuild)->toBeLessThan(2.0, "rebuild {$rebuild} s");

    // Freezing the shift at handover (close step 4) with 300 in-house stays ≤ 2 s.
    $by = Hotel::frontdesk();
    $shift = Hotel::closeToStep4($by);
    [$freeze, , $next] = measure(fn () => app(HandOverShift::class)->toReceiver(
        $by, Hotel::branch()->id, Hotel::user('rhea.bautista'), Hotel::PIN,
        Money::ofCentavos($shift->counted_cash?->centavos() ?? 0), Hotel::device(), 'Handover note',
    ));
    budgetNote('handover freeze, 300 in house', $freeze);
    expect($freeze)->toBeLessThan(2.0, "freeze {$freeze} s")
        ->and($next->id)->not->toBe($shift->id);

    // Shift report and Z-report of the closed shift ≤ 500 ms each (frozen totals).
    $closed = $shift->fresh();
    $reports = app(ReportService::class);
    [$report] = measure(fn () => $reports->shift($closed));
    [$z] = measure(fn () => $reports->zReport($closed));
    budgetNote('shift report (closed)', $report);
    budgetNote('Z-report (closed)', $z);
    expect($report)->toBeLessThan(0.5, "shift report {$report} s")
        ->and($z)->toBeLessThan(0.5, "Z-report {$z} s");
})->group('performance');

it('meets the dashboard first-response budget', function (): void {
    Hotel::openShift();
    Hotel::checkIn('101', 6);
    app(RebuildSummaries::class)->run();

    foreach ([['ramil.ortega', '/dashboard'], ['ramon.alvarez', '/owner']] as [$user, $url]) {
        $this->actingAs(Hotel::user($user))->get($url);
        [$t] = measure(fn () => $this->get($url)->assertOk());
        budgetNote("{$url} first response", $t);
        expect($t)->toBeLessThan(0.3, "{$url} first response {$t} s");
    }
})->group('performance');

it('meets the Stats, daily and owner summary budgets on a generated year', function (): void {
    // One real day closed through the actions, then copied onto the 364 days before it.
    Hotel::openShift();
    foreach (['101', '102', '205', '206', '301'] as $room) {
        Hotel::checkIn($room, 6);
    }
    Hotel::closeAndHandOver(Hotel::user('rhea.bautista'));
    app(RebuildSummaries::class)->day(Hotel::branch()->id, '2026-11-10');
    yearOfSummaries('2026-11-10', 364);
    yearOfShifts(364);

    $admin = Hotel::user('ramil.ortega');
    $owner = Hotel::user('ramon.alvarez');
    $ids = [Hotel::branch()->id];

    // Stats (63) for one year ≤ 1 s: every figure of the page.
    $stats = app(StatsService::class);
    [$t] = measure(function () use ($stats, $ids): void {
        [$from, $to] = ['2025-11-11', '2026-11-10'];
        $stats->totals($ids, $from, $to);
        $stats->series($ids, $from, $to);
        $stats->heat($ids, $from, $to);
        foreach (['stay_option', 'room_type', 'product'] as $section) {
            $stats->lines($ids, $from, $to, $section);
        }
        $stats->otherSales($ids, $from, $to);
        $stats->methods($ids, $from, $to);
        $stats->occupancy($ids, $from, $to);
        $stats->frontDesk($ids, $from, $to);
    });
    budgetNote('Stats, one year', $t);
    expect($t)->toBeLessThan(1.0, "Stats one year {$t} s");

    $this->actingAs($admin)->get('/stats?from=2025-11-11&to=2026-11-10')
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(['cards', 'series', 'charts', 'tables'], fn (Assert $r) => $r->where('series.grain', 'month')));

    // Daily (38) and owner (39) summaries over a year of closed shifts ≤ 1.5 s.
    $reports = app(ReportService::class);
    [$year] = measure(fn () => $reports->summary($ids, '2025-11-11', '2026-11-10'));
    budgetNote('owner summary, one year', $year);
    expect($year)->toBeLessThan(1.5, "owner summary, one year {$year} s");

    foreach ([[$owner, '/owner/summary?range=month&date=2026-10-15'], [$admin, '/reports/daily?range=month&date=2026-10-15']] as [$user, $url]) {
        $this->actingAs($user)->get($url);
        [$t] = measure(fn () => $this->get($url)->assertOk());
        budgetNote($url, $t);
        expect($t)->toBeLessThan(1.5, "{$url} {$t} s");
    }
})->group('performance');
