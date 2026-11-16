<?php

declare(strict_types=1);

namespace App\Http\Controllers\Owner;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Services\AttentionList;
use App\Services\DashboardService;
use App\Support\Clock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner dashboard (60) at /owner (TRD §4.11, APP_FLOW §4.24): all branches or one, chosen with
 * `branch` in the query string; the period with `period`. Every figure is a deferred prop.
 */
final class DashboardController extends Controller
{
    public const PERIODS = ['today', 'yesterday', 'week', 'month', 'year'];

    public function index(Request $request, DashboardService $dash, AttentionList $attention, Clock $clock): Response
    {
        /** @var User $user */
        $user = $request->user();
        $all = Branch::query()->withoutGlobalScopes()->orderBy('name')->get(['id', 'name', 'status', 'payment_methods']);
        $chosen = $request->filled('branch') && $request->query('branch') !== 'all' ? $all->firstWhere('id', $request->integer('branch')) : null;
        $ids = $chosen === null ? $all->pluck('id')->map(fn ($id): int => (int) $id)->all() : [$chosen->id];
        $period = in_array($request->query('period'), self::PERIODS, true) ? (string) $request->query('period') : 'today';
        $methods = collect($chosen === null ? $all : [$chosen])->flatMap(fn (Branch $b): array => $b->payment_methods->map(fn (PaymentMethod $m): string => $m->value)->all())->unique()->values()->all();

        return Inertia::render('Dashboard/Owner', [
            'period' => $period,
            'branch' => $chosen === null ? 'all' : $chosen->id,
            'branches' => $all->map(fn (Branch $b): array => ['id' => $b->id, 'name' => $b->name])->values()->all(),
            'polling' => (int) config('hotel.polling.dashboard', 30),
            'kpis' => Inertia::defer(fn (): array => [
                'totals' => $dash->totals($dash->periods($ids, $period)),
                'rooms' => $dash->roomsNow($ids),
                'branches' => count($ids),
                'at' => $clock->now()->toIso8601String(),
            ], 'kpis'),
            'charts' => Inertia::defer(fn (): array => [
                'branches' => $dash->salesByBranch($ids),
                'methods' => $dash->paymentsByMethod($dash->periods($ids, $period), $methods),
                'overview' => $dash->salesOverview($dash->periods($ids, $period), $methods),
            ], 'charts'),
            'now' => Inertia::defer(fn (): array => ['branches' => $dash->branchesNow($ids)], 'now'),
            'attention' => Inertia::defer(fn (): array => [
                // One more than shown, so the card knows to offer "View all".
                'rows' => array_slice($attention->rows($ids, $user), 0, 6),
                'more' => count($ids) === 1 ? null : route('owner.branches.index', absolute: false),
            ], 'attention'),
        ]);
    }
}
