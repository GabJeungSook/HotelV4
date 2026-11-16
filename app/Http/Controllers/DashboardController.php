<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\ShiftDefinition;
use App\Models\User;
use App\Queries\ShiftCalendarQuery;
use App\Services\AttentionList;
use App\Services\CurrentBranch;
use App\Services\Dashboard\Period;
use App\Services\DashboardService;
use App\Support\Clock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branch dashboard (61) at /dashboard (TRD §4.11, APP_FLOW §4.24, SYSTEM_DESIGN §6.6): admin,
 * back office and supervisor of the branch; an owner who opened the branch. The first response
 * is the frame and the period only; every figure is a deferred prop in its own group, reloaded
 * every 30 seconds by a partial reload.
 */
final class DashboardController extends Controller
{
    public const PERIODS = ['today', 'yesterday', 'week', 'month'];

    /** The period of the Sales overview card, chosen on the card itself (mockup v6). */
    public const OVERVIEW = ['today', 'week', 'month'];

    public function index(Request $request, CurrentBranch $branches, DashboardService $dash, AttentionList $attention, Clock $clock, ShiftCalendarQuery $calendar): Response
    {
        /** @var User $user */
        $user = $request->user();
        $branch = $branches->branch() ?? abort(403);
        // A specific from–to range overrides the named period when both dates are valid.
        $from = $request->query('from');
        $to = $request->query('to');
        $custom = is_string($from) && is_string($to)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1;
        $period = $custom ? 'custom' : (in_array($request->query('period'), self::PERIODS, true) ? (string) $request->query('period') : 'today');
        $periods = fn (): array => $custom ? $dash->customPeriods([$branch->id], (string) $from, (string) $to) : $dash->periods([$branch->id], $period);
        $range = (fn (Period $p): array => ['from' => $p->from, 'to' => $p->to])($periods()[$branch->id]);
        $overview = in_array($request->query('overview'), self::OVERVIEW, true) ? (string) $request->query('overview') : 'week';
        $methods = $branch->payment_methods->map(fn (PaymentMethod $m): string => $m->value)->all();
        $desk = $request->filled('desk') ? $request->integer('desk') : null;
        $band = $request->filled('band') ? (string) $request->query('band') : null;

        return Inertia::render('Dashboard/Branch', [
            'period' => $period,
            'range' => $range,
            'overviewPeriod' => $overview,
            'filters' => ['week' => $request->query('week'), 'desk' => $desk, 'band' => $band],
            'branch' => ['id' => $branch->id, 'name' => $branch->name, 'status' => $branch->status->value],
            'polling' => (int) config('hotel.polling.dashboard', 30),
            'kpis' => Inertia::defer(fn (): array => [
                'totals' => $dash->totals($periods()),
                'rooms' => $dash->roomsNow([$branch->id]),
                'unpaid' => $dash->unpaid($branch->id),
                'at' => $clock->now()->toIso8601String(),
            ], 'kpis'),
            'charts' => Inertia::defer(fn (): array => [
                'nights' => $dash->occupancyNights($branch->id),
                'period' => $periods()[$branch->id]->words(),
            ], 'charts'),
            'overview' => Inertia::defer(fn (): array => $dash->salesOverview($dash->periods([$branch->id], $overview), $methods), 'overview'),
            'recent' => Inertia::defer(fn (): array => ['rows' => $dash->recentCheckins($branch->id)], 'recent'),
            'calendar' => Inertia::defer(fn (): array => $calendar->week($branch->id, $request->query('week') === null ? null : (string) $request->query('week'), $desk, $band), 'calendar'),
            'attention' => Inertia::defer(fn (): array => [
                // One more than shown, so the card knows to offer "View all" (49).
                'rows' => array_slice($attention->rows([$branch->id], $user), 0, 6),
                'more' => in_array($user->role, [Role::Admin, Role::Supervisor, Role::Owner], true) ? route('admin.exceptions', absolute: false) : null,
            ], 'attention'),
        ]);
    }

    /** The hour the branch's first shift starts, so "sales by hour" reads like its day. */
    public static function startHour(Branch $branch): int
    {
        $start = ShiftDefinition::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_active', true)
            ->orderBy('sort_order')->value('starts_at');

        return $start === null ? 0 : (int) substr((string) $start, 0, 2);
    }
}
