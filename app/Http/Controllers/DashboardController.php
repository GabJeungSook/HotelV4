<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\ShiftDefinition;
use App\Models\User;
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

    public function index(Request $request, CurrentBranch $branches, DashboardService $dash, AttentionList $attention, Clock $clock): Response
    {
        /** @var User $user */
        $user = $request->user();
        $branch = $branches->branch() ?? abort(403);
        $period = in_array($request->query('period'), self::PERIODS, true) ? (string) $request->query('period') : 'today';
        $periods = fn (): array => $dash->periods([$branch->id], $period);

        return Inertia::render('Dashboard/Branch', [
            'period' => $period,
            'branch' => ['id' => $branch->id, 'name' => $branch->name, 'status' => $branch->status->value],
            'polling' => (int) config('hotel.polling.dashboard', 30),
            'kpis' => Inertia::defer(fn (): array => [
                'totals' => $dash->totals($periods()),
                'rooms' => $dash->roomsNow([$branch->id]),
                'unpaid' => $dash->unpaid($branch->id),
                'at' => $clock->now()->toIso8601String(),
            ], 'kpis'),
            'charts' => Inertia::defer(fn (): array => [
                'rooms' => $dash->roomsNow([$branch->id]),
                'hours' => $dash->salesByHour($periods(), self::startHour($branch)),
                'period' => Period::of($period, $periods()[$branch->id]->to)->words(),
            ], 'charts'),
            'shift' => Inertia::defer(fn (): array => [
                'shift' => $dash->openShift($branch->id, $user),
                'housekeeping' => $branch->housekeeping_enabled ? $dash->housekeeping($branch->id, $dash->periods([$branch->id], 'today')) : null,
            ], 'shift'),
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
