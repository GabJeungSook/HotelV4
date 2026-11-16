<?php

declare(strict_types=1);

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\ReportService;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner summary (39) at /owner/summary: sales, check-ins, occupancy, short and over across
 * branches for a week (the 7 days ending `date`) or a calendar month, for all branches or one.
 * Built from the closed shifts' frozen totals through ReportService::summary, so its totals
 * equal the sum of the daily reports (it never reads the summary tables).
 */
final class SummaryController extends Controller
{
    public function index(Request $request, ReportService $reports, Clock $clock): Response
    {
        $all = Branch::query()->withoutGlobalScopes()->orderBy('name')->get(['id', 'name']);
        $chosen = $request->filled('branch') && $request->query('branch') !== 'all' ? $all->firstWhere('id', $request->integer('branch')) : null;
        $ids = $chosen === null ? $all->pluck('id')->map(fn ($id): int => (int) $id)->all() : [$chosen->id];
        $range = $request->query('range') === 'month' ? 'month' : 'week';

        $date = is_string($request->query('date')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query('date')) === 1
            ? CarbonImmutable::parse($request->query('date'))
            : CarbonImmutable::parse($clock->localDate())->subDay();
        [$from, $to] = $range === 'month' ? [$date->startOfMonth(), $date->endOfMonth()->startOfDay()] : [$date->subDays(6), $date];

        $days = [];
        for ($d = $from; $d->lessThanOrEqualTo($to); $d = $d->addDay()) {
            $days[] = ['date' => $d->toDateString(), 'sales' => (int) $reports->summary($ids, $d->toDateString(), $d->toDateString())['total']['sales']];
        }

        // The matching window just before this one, so the cards can show the change.
        $length = (int) $from->diffInDays($to) + 1;
        $prevTo = $from->subDay();
        $prevFrom = $prevTo->subDays($length - 1);

        return Inertia::render('Owner/Summary', [
            'range' => $range,
            'date' => $date->toDateString(),
            'branch' => $chosen === null ? 'all' : $chosen->id,
            'branches' => $all->map(fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name])->values()->all(),
            'summary' => $reports->summary($ids, $from->toDateString(), $to->toDateString()),
            'compare' => $reports->summary($ids, $prevFrom->toDateString(), $prevTo->toDateString())['total'],
            'breakdown' => $reports->salesBreakdown($ids, $from->toDateString(), $to->toDateString()),
            'days' => $days,
        ]);
    }
}
