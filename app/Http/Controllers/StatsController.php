<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use App\Services\CurrentBranch;
use App\Services\StatsService;
use App\Services\ZReportExport;
use App\Summaries\SummaryDays;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stats (63) at /stats (TRD §4.11, APP_FLOW §2): a date range from `from` and `to`; for an
 * owner, `branch` (all or one). Reads the summary tables only, through StatsService. Every
 * figure is a deferred prop; ?export=csv streams the tables (TRD §6).
 */
final class StatsController extends Controller
{
    public function index(Request $request, StatsService $stats, CurrentBranch $branches, SummaryDays $days, ZReportExport $export, Clock $clock): Response|StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $all = $user->role === Role::Owner ? Branch::query()->withoutGlobalScopes()->orderBy('name')->get(['id', 'name']) : collect();

        if ($user->role === Role::Owner) {
            $pick = $request->query('branch', $branches->id() === null ? 'all' : (string) $branches->id());
            $chosen = $pick === 'all' ? null : $all->firstWhere('id', (int) $pick);
            $ids = $chosen === null ? $all->pluck('id')->map(fn ($id): int => (int) $id)->all() : [$chosen->id];
            $branchProp = $chosen === null ? 'all' : $chosen->id;
            $todayOf = $chosen === null ? $all->first()?->id : $chosen->id;
        } else {
            $ids = [(int) $user->branch_id];
            $branchProp = (int) $user->branch_id;
            $todayOf = (int) $user->branch_id;
        }

        $today = $todayOf === null ? $clock->localDate() : $days->currentDate($todayOf);
        [$from, $to] = self::range($request, $today);

        if ($request->query('export') === 'csv') {
            return $export->table(self::tables($stats, $ids, $from, $to), $user, $ids[0] ?? (int) $user->branch_id, null, "stats-{$from}-{$to}.csv");
        }

        return Inertia::render('Stats/Index', [
            'from' => $from,
            'to' => $to,
            'today' => $today,
            'grain' => StatsService::grain($from, $to),
            'branch' => $branchProp,
            'branches' => $all->map(fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name])->values()->all(),
            'totals' => Inertia::defer(fn (): array => $stats->totals($ids, $from, $to), 'cards'),
            'series' => Inertia::defer(fn (): array => $stats->series($ids, $from, $to), 'series'),
            'heat' => Inertia::defer(fn (): array => $stats->heat($ids, $from, $to), 'charts'),
            'chose' => Inertia::defer(fn (): array => $stats->lines($ids, $from, $to, 'stay_option'), 'charts'),
            'room_types' => Inertia::defer(fn (): array => ['rows' => $stats->lines($ids, $from, $to, 'room_type'), 'other' => $stats->otherSales($ids, $from, $to)], 'charts'),
            'products' => Inertia::defer(fn (): array => $stats->lines($ids, $from, $to, 'product', 8), 'tables'),
            'methods' => Inertia::defer(fn (): array => $stats->methods($ids, $from, $to), 'tables'),
            'occupancy' => Inertia::defer(fn (): array => $stats->occupancy($ids, $from, $to), 'tables'),
            'front_desk' => Inertia::defer(fn (): array => $stats->frontDesk($ids, $from, $to), 'tables'),
        ]);
    }

    /**
     * The range from the query string, else this month so far; at most two years, never reversed.
     *
     * @return array{0: string, 1: string}
     */
    private static function range(Request $request, string $today): array
    {
        $parse = function (mixed $value): ?CarbonImmutable {
            if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                return null;
            }

            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $value)?->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        };

        $t = CarbonImmutable::parse($today);
        $from = $parse($request->query('from')) ?? $t->startOfMonth();
        $to = $parse($request->query('to')) ?? $t;

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > 731) {
            $from = $to->subDays(731);
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    /**
     * The tables of the page for the CSV export.
     *
     * @param  list<int>  $ids
     * @return list<array{title: string, columns: list<string>, rows: list<list<string|int>>}>
     */
    private static function tables(StatsService $stats, array $ids, string $from, string $to): array
    {
        $peso = fn (int $c): string => number_format($c / 100, 2, '.', '');
        $series = $stats->series($ids, $from, $to);

        return [
            ['title' => "Sales by {$series['grain']}", 'columns' => ['Period', 'Check-ins', 'Sales'], 'rows' => array_map(fn (array $p): array => [$p['key'], $p['checkins'], $peso($p['sales'])], $series['points'])],
            ['title' => 'Sales by room type', 'columns' => ['Room type', 'Stays', 'Sales'], 'rows' => array_map(fn (array $r): array => [$r['label'], $r['quantity'], $peso($r['amount'])], $stats->lines($ids, $from, $to, 'room_type'))],
            ['title' => 'What guests chose', 'columns' => ['Option', 'Stays', 'Room sales'], 'rows' => array_map(fn (array $r): array => [$r['label'], $r['quantity'], $peso($r['amount'])], $stats->lines($ids, $from, $to, 'stay_option'))],
            ['title' => 'Products', 'columns' => ['Product', 'Quantity', 'Sales'], 'rows' => array_map(fn (array $r): array => [$r['label'], $r['quantity'], $peso($r['amount'])], $stats->lines($ids, $from, $to, 'product'))],
            ['title' => 'Payment methods', 'columns' => ['Method', 'Payments', 'Collected', 'Given back'], 'rows' => array_map(fn (array $r): array => [$r['method'], $r['count'], $peso($r['amount']), $peso($r['refunds'])], $stats->methods($ids, $from, $to))],
            ['title' => 'Front desk', 'columns' => ['Name', 'Payments', 'Collected', 'Given back'], 'rows' => array_map(fn (array $r): array => [$r['name'], $r['count'], $peso($r['amount']), $peso($r['refunds'])], $stats->frontDesk($ids, $from, $to))],
        ];
    }
}
