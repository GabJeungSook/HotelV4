<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CleaningStatus;
use App\Enums\PaymentKind;
use App\Enums\Role;
use App\Enums\RoomStatus;
use App\Enums\ShiftStatus;
use App\Enums\StayState;
use App\Models\Branch;
use App\Models\CleaningTask;
use App\Models\Room;
use App\Models\ShiftMember;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\User;
use App\Services\Dashboard\Period;
use App\Summaries\SummaryDays;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * The figures of the owner dashboard (60) and the branch dashboard (61), one method per
 * deferred card group (TRD §4.11, SYSTEM_DESIGN §5.2, §6.6).
 *
 * Money and counts over a period come from the summary tables only (daily_summaries,
 * hourly_sales_summaries, daily_payment_summaries). Live state is read directly and bounded to
 * now: rooms by status, in-house stays, kiosk holds, the open shift and its members, and each
 * member's check-ins and collections on that open shift (one grouped query on payments and one
 * on stays, by shift id). No request sums folio_lines or payments over a period.
 *
 * "Today" is each branch's current business date. Short and over are summed separately.
 */
final class DashboardService
{
    public function __construct(
        private readonly SummaryDays $days,
        private readonly Clock $clock,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * The period of each branch.
     *
     * @param  list<int>  $branchIds
     * @return array<int, Period>
     */
    public function periods(array $branchIds, string $key): array
    {
        $out = [];
        foreach ($branchIds as $id) {
            $out[$id] = Period::of($key, $this->days->currentDate($id));
        }

        return $out;
    }

    /**
     * A chosen "from–to" range: the same calendar dates for every branch (TRD §4.11, dashboard
     * date range). Read the same way as the named periods, compared with the window just before.
     *
     * @param  list<int>  $branchIds
     * @return array<int, Period>
     */
    public function customPeriods(array $branchIds, string $from, string $to): array
    {
        $period = Period::ofRange($from, $to);

        return array_fill_keys($branchIds, $period);
    }

    /* ------------------------------------------------------------------ KPIs */

    /**
     * Sales of the period with the change figure, check-ins and check-outs, short and over.
     *
     * @param  array<int, Period>  $periods
     * @return array{period: string, compared_with: string, sales: int, previous: int|null, change: int|null, checkins: int, checkouts: int, short: int, over: int, from_last_night: int|null}
     */
    public function totals(array $periods): array
    {
        $first = reset($periods) ?: Period::of('today', $this->clock->localDate());
        $now = $this->sum('daily_summaries', $periods, 'from', 'to', [
            'sales' => 'net_sales_amount', 'checkins' => 'checkins', 'checkouts' => 'checkouts', 'short' => 'short_amount', 'over' => 'over_amount',
        ]);
        $previous = $first->key === 'today' ? $this->todayByThisTime($periods) : $this->previousSales($periods);

        return [
            'period' => $first->words(),
            'compared_with' => $first->comparedWith(),
            'sales' => $now['sales'],
            'previous' => $previous,
            'change' => self::change($now['sales'], $previous),
            'checkins' => $now['checkins'],
            'checkouts' => $now['checkouts'],
            'short' => $now['short'],
            'over' => $now['over'],
            'from_last_night' => $first->key === 'today' ? $this->inHouseAtEndOf($periods, 'previousTo') : null,
        ];
    }

    /**
     * Rooms now (live): by status, with held rooms out of "vacant".
     *
     * @param  list<int>  $branchIds
     * @return array{total: int, occupied: int, vacant: int, to_clean: int, cleaning: int, held: int, blocked: int}
     */
    public function roomsNow(array $branchIds): array
    {
        $byStatus = Room::query()->withoutGlobalScopes()
            ->whereIn('branch_id', $branchIds)->where('is_active', true)
            ->groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status');
        $held = Stay::query()->withoutGlobalScopes()
            ->whereIn('branch_id', $branchIds)->where('state', StayState::Held)
            ->where('hold_expires_at', '>', $this->clock->now())->whereNotNull('room_id')->count();
        $count = fn (RoomStatus $s): int => (int) ($byStatus[$s->value] ?? 0);
        $available = $count(RoomStatus::Available);

        return [
            'total' => (int) $byStatus->sum(),
            'occupied' => $count(RoomStatus::Occupied),
            'vacant' => max(0, $available - $held),
            'to_clean' => $count(RoomStatus::Uncleaned),
            'cleaning' => $count(RoomStatus::Cleaning),
            'held' => min($held, $available),
            'blocked' => $count(RoomStatus::Maintenance),
        ];
    }

    /**
     * Unpaid balances of the guests in house now (live, bounded to the in-house stays).
     *
     * @return array{amount: int, folios: int}
     */
    public function unpaid(int $branchId): array
    {
        $paid = $this->db->table('payment_allocations')->groupBy('folio_line_id')->selectRaw('folio_line_id, SUM(amount) AS paid');
        $rows = $this->db->table('folio_lines')
            ->join('stays', 'stays.id', '=', 'folio_lines.stay_id')
            ->leftJoinSub($paid, 'pa', 'pa.folio_line_id', '=', 'folio_lines.id')
            ->where('stays.branch_id', $branchId)->where('stays.state', StayState::CheckedIn->value)
            ->groupBy('folio_lines.stay_id')
            ->havingRaw('SUM(folio_lines.amount) - COALESCE(SUM(pa.paid), 0) > 0')
            ->selectRaw('folio_lines.stay_id, SUM(folio_lines.amount) - COALESCE(SUM(pa.paid), 0) AS due')
            ->get();

        return ['amount' => (int) $rows->sum('due'), 'folios' => $rows->count()];
    }

    /* ---------------------------------------------------------------- charts */

    /**
     * Sales per business date over the last 7 days ending today (owner 60).
     *
     * @param  array<int, Period>  $periods
     * @return list<array{date: string, sales: int}>
     */
    public function lastSevenDays(array $periods): array
    {
        $week = [];
        foreach ($periods as $id => $p) {
            $week[$id] = new Period('week', CarbonImmutable::parse($p->to)->subDays(6)->toDateString(), $p->to, $p->from, $p->from);
        }

        $first = reset($week);
        $byDate = $this->rangeQuery('daily_summaries', $week, 'from', 'to')
            ->groupBy('business_date')->selectRaw('business_date, SUM(net_sales_amount) AS s')->pluck('s', 'business_date')
            ->mapWithKeys(fn ($v, $k): array => [substr((string) $k, 0, 10) => (int) $v]);

        $out = [];
        if ($first !== false) {
            for ($d = CarbonImmutable::parse($first->from); $d->lessThanOrEqualTo(CarbonImmutable::parse($first->to)); $d = $d->addDay()) {
                $out[] = ['date' => $d->toDateString(), 'sales' => (int) ($byDate[$d->toDateString()] ?? 0)];
            }
        }

        return $out;
    }

    /**
     * How guests paid in the period, by method (daily_payment_summaries).
     *
     * @param  array<int, Period>  $periods
     * @param  list<string>  $methods  the methods to list even at zero
     * @return list<array{method: string, amount: int, count: int}>
     */
    public function paymentsByMethod(array $periods, array $methods): array
    {
        $rows = $this->rangeQuery('daily_payment_summaries', $periods, 'from', 'to')
            ->groupBy('method')->selectRaw('method, SUM(amount) AS a, SUM(payment_count) AS c')->get()->keyBy('method');
        $list = array_values(array_unique([...$methods, ...$rows->keys()->map(fn ($k): string => (string) $k)->all()]));

        return array_map(fn (string $m): array => ['method' => $m, 'amount' => (int) ($rows[$m]->a ?? 0), 'count' => (int) ($rows[$m]->c ?? 0)], $list);
    }

    /**
     * Sales by local hour of the day for the period (61): today and yesterday hour by hour;
     * longer periods add each hour of the day across the range.
     *
     * @param  array<int, Period>  $periods
     * @return array{start_hour: int, hours: list<int>, sales: list<int>}
     */
    public function salesByHour(array $periods, int $startHour): array
    {
        $byHour = $this->rangeQuery('hourly_sales_summaries', $periods, 'from', 'to')
            ->groupBy('hour')->selectRaw('hour, SUM(net_sales_amount) AS s')->pluck('s', 'hour');
        $hours = [];
        $sales = [];

        for ($i = 0; $i < 24; $i++) {
            $h = ($startHour + $i) % 24;
            $hours[] = $h;
            $sales[] = (int) ($byHour[$h] ?? 0);
        }

        return ['start_hour' => $startHour, 'hours' => $hours, 'sales' => $sales];
    }

    /**
     * Rooms in use at the end of each business day over the last `days` days (61, mockup v6
     * Occupancy chart), from daily_summaries.stays_in_house_end; today's bar is the rooms occupied
     * now. Per-night "to clean" and "blocked" counts are not kept in the summaries.
     *
     * @return array{total: int, days: list<array{date: string, occupied: int}>}
     */
    public function occupancyNights(int $branchId, int $days = 14): array
    {
        $today = $this->days->currentDate($branchId);
        $from = CarbonImmutable::parse($today)->subDays($days - 1)->toDateString();
        $rows = $this->db->table('daily_summaries')->where('branch_id', $branchId)->whereBetween('business_date', [$from, $today])
            ->get(['business_date', 'stays_in_house_end'])
            ->mapWithKeys(fn (object $r): array => [substr((string) $r->business_date, 0, 10) => (int) $r->stays_in_house_end]);
        $live = Room::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('is_active', true)
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(status = ?), 0) AS occupied', [RoomStatus::Occupied->value])->first();
        $live = ['total' => (int) ($live?->getAttribute('total') ?? 0), 'occupied' => (int) ($live?->getAttribute('occupied') ?? 0)];

        $out = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $today; $d = $d->addDay()) {
            $date = $d->toDateString();
            $out[] = ['date' => $date, 'occupied' => $date === $today ? $live['occupied'] : (int) ($rows[$date] ?? 0)];
        }

        return ['total' => $live['total'], 'days' => $out];
    }

    /**
     * Net sales per business date per branch over the last `days` days (60, Sales by branch).
     *
     * @param  list<int>  $branchIds
     * @return array{dates: list<string>, branches: list<array{id: int, name: string, sales: list<int>}>}
     */
    public function salesByBranch(array $branchIds, int $days = 14): array
    {
        $today = $this->clock->localDate();
        $from = CarbonImmutable::parse($today)->subDays($days - 1)->toDateString();
        $rows = $this->db->table('daily_summaries')->whereIn('branch_id', $branchIds)->whereBetween('business_date', [$from, $today])
            ->get(['branch_id', 'business_date', 'net_sales_amount']);
        $names = Branch::query()->withoutGlobalScopes()->whereIn('id', $branchIds)->orderBy('name')->pluck('name', 'id');

        $dates = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $today; $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r->branch_id][substr((string) $r->business_date, 0, 10)] = (int) $r->net_sales_amount;
        }

        $branches = [];
        foreach ($names as $id => $name) {
            $branches[] = ['id' => (int) $id, 'name' => (string) $name, 'sales' => array_map(fn (string $d): int => $by[(int) $id][$d] ?? 0, $dates)];
        }

        return ['dates' => $dates, 'branches' => $branches];
    }

    /**
     * Sales overview (61, 60 "How guests paid"): total net sales of the period, rooms and extras,
     * and the share of each payment method, from daily_summaries and daily_payment_summaries.
     *
     * @param  array<int, Period>  $periods
     * @param  list<string>  $methods
     * @return array{period: string, from: string, to: string, sales: int, rooms: int, extras: int, methods: list<array{method: string, amount: int, count: int}>}
     */
    public function salesOverview(array $periods, array $methods): array
    {
        $first = reset($periods) ?: Period::of('today', $this->clock->localDate());
        $sum = $this->sum('daily_summaries', $periods, 'from', 'to', ['sales' => 'net_sales_amount', 'rooms' => 'room_sales_amount', 'extras' => 'other_sales_amount']);

        return [
            'period' => $first->words(),
            'from' => $first->from,
            'to' => $first->to,
            'sales' => $sum['sales'],
            'rooms' => $sum['rooms'],
            'extras' => $sum['extras'],
            'methods' => $this->paymentsByMethod($periods, $methods),
        ];
    }

    /**
     * The last check-ins at the branch (61, live state bounded to the newest rows): room, guest and
     * when. No amounts.
     *
     * @return list<array{stay_id: int, room: string, guest: string, at: string}>
     */
    public function recentCheckins(int $branchId, int $limit = 5): array
    {
        return Stay::query()->withoutGlobalScopes()
            ->join('rooms', 'rooms.id', '=', 'stays.room_id')
            ->where('stays.branch_id', $branchId)->whereNotNull('stays.checked_in_at')
            ->orderByDesc('stays.checked_in_at')->limit($limit)
            ->get(['stays.id', 'stays.guest_name', 'stays.checked_in_at', 'rooms.number as room_number'])
            ->map(fn (Stay $s): array => [
                'stay_id' => $s->id,
                'room' => (string) $s->getAttribute('room_number'),
                'guest' => $s->guest_name,
                'at' => $s->checked_in_at?->toIso8601String() ?? '',
            ])->values()->all();
    }

    /* ------------------------------------------------------------ live state */

    /**
     * The open shift with each front desk's check-ins and collections so far (61). For managers
     * only: never call this for a front desk user (TRD §4.11, CLAUDE.md rule 9).
     *
     * @return array{id: int, period_name: string, status: string, opened_at: string, members: list<array{id: int, name: string, checkins: int, collected: int}>}|null
     */
    public function openShift(int $branchId, User $viewer): ?array
    {
        if (! in_array($viewer->role, [Role::Admin, Role::Backoffice, Role::Supervisor, Role::Owner], true)) {
            return null;
        }

        $shift = ShiftSession::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)->whereIn('status', [ShiftStatus::Open, ShiftStatus::Closing])->first();

        if ($shift === null) {
            return null;
        }

        // One query for the members with their check-ins and collections on this shift.
        $members = $this->db->table('shift_members')
            ->join('users', 'users.id', '=', 'shift_members.user_id')
            ->where('shift_members.shift_session_id', $shift->id)
            ->orderBy('shift_members.joined_at')->orderBy('shift_members.id')
            ->select(['users.id', 'users.name'])
            ->selectSub(fn (Builder $q) => $q->from('stays')->selectRaw('COUNT(*)')->where('stays.checkin_shift_id', $shift->id)->whereColumn('stays.checked_in_by', 'users.id'), 'checkins')
            ->selectSub(fn (Builder $q) => $q->from('payments')->selectRaw('COALESCE(SUM(amount), 0)')->where('payments.shift_session_id', $shift->id)
                ->where('payments.kind', PaymentKind::Payment->value)->whereColumn('payments.created_by', 'users.id'), 'collected')
            ->get();

        return [
            'id' => $shift->id,
            'period_name' => $shift->period_name,
            'status' => $shift->status->value,
            'opened_at' => $shift->opened_at->toIso8601String(),
            'members' => $members->map(fn (object $m): array => [
                'id' => (int) $m->id,
                'name' => (string) $m->name,
                'checkins' => (int) $m->checkins,
                'collected' => (int) $m->collected,
            ])->values()->all(),
        ];
    }

    /**
     * Branches now (60): each branch's open shift and who is on it, rooms occupied and today's
     * sales so far (from daily_summaries).
     *
     * @param  list<int>  $branchIds
     * @return list<array{id: int, name: string, status: string, shift: array{period_name: string, opened_at: string}|null, on_duty: list<string>, occupied: int, rooms: int, sales: int}>
     */
    public function branchesNow(array $branchIds): array
    {
        $branches = Branch::query()->withoutGlobalScopes()->whereIn('id', $branchIds)->orderBy('name')->get(['id', 'name', 'status']);
        $shifts = ShiftSession::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)
            ->whereIn('status', [ShiftStatus::Open, ShiftStatus::Closing])->get()->keyBy('branch_id');
        $onDuty = ShiftMember::query()->with('user')->whereIn('shift_session_id', $shifts->pluck('id')->all())->orderBy('joined_at')->get()
            ->groupBy('shift_session_id');
        $rooms = Room::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->where('is_active', true)
            ->groupBy('branch_id')->selectRaw("branch_id, COUNT(*) AS total, SUM(status = 'occupied') AS occupied")->get()->keyBy('branch_id');
        $today = $this->periods($branchIds, 'today');
        $sales = $this->rangeQuery('daily_summaries', $today, 'from', 'to')->groupBy('branch_id')
            ->selectRaw('branch_id, SUM(net_sales_amount) AS s')->pluck('s', 'branch_id');

        return $branches->map(function (Branch $b) use ($shifts, $onDuty, $rooms, $sales): array {
            $shift = $shifts->get($b->id);

            return [
                'id' => $b->id,
                'name' => $b->name,
                'status' => $b->status->value,
                'shift' => $shift === null ? null : ['period_name' => $shift->period_name, 'opened_at' => $shift->opened_at->toIso8601String()],
                'on_duty' => $shift === null ? [] : $onDuty->get($shift->id, collect())->map(fn (ShiftMember $m): string => $m->user->name)->values()->all(),
                'occupied' => (int) ($rooms[$b->id]->occupied ?? 0),
                'rooms' => (int) ($rooms[$b->id]->total ?? 0),
                'sales' => (int) ($sales[$b->id] ?? 0),
            ];
        })->values()->all();
    }

    /**
     * Housekeeping today (61): cleans and late cleans from the summary, the average minutes from
     * today's finished tasks.
     *
     * @param  array<int, Period>  $periods
     * @return array{cleans: int, late: int, average_minutes: int|null}
     */
    public function housekeeping(int $branchId, array $periods): array
    {
        $sum = $this->sum('daily_summaries', $periods, 'from', 'to', ['cleans' => 'cleans', 'late' => 'late_cleans']);
        $p = $periods[$branchId] ?? null;
        $average = null;

        if ($p !== null) {
            $from = CarbonImmutable::parse($p->from, Clock::displayTimezone())->startOfDay()->utc();
            $tasks = CleaningTask::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('status', CleaningStatus::Done)
                ->where('finished_at', '>=', $from)->whereNotNull('started_at')->get(['started_at', 'finished_at']);
            $average = $tasks->isEmpty() ? null : (int) round($tasks->avg(fn (CleaningTask $t): int => intdiv(($t->finished_at?->getTimestamp() ?? 0) - ($t->started_at?->getTimestamp() ?? 0), 60)));
        }

        return ['cleans' => $sum['cleans'], 'late' => $sum['late'], 'average_minutes' => $average];
    }

    /* --------------------------------------------------------------- helpers */

    /** Percent change, or null with no earlier figure to compare with (TRD §4.11: never "+100%"). */
    public static function change(int $now, ?int $previous): ?int
    {
        if ($previous === null || $previous === 0) {
            return null;
        }

        return (int) round(($now - $previous) * 100 / abs($previous));
    }

    /**
     * Yesterday's sales up to the same time of day, from hourly_sales_summaries; null when
     * yesterday has no rows at all.
     *
     * @param  array<int, Period>  $periods
     */
    private function todayByThisTime(array $periods): ?int
    {
        $cut = $this->clock->local()->subDay();
        $row = $this->rangeQuery('hourly_sales_summaries', $periods, 'previousFrom', 'previousTo')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(CASE WHEN local_date < ? OR (local_date = ? AND hour <= ?) THEN net_sales_amount ELSE 0 END), 0) AS s', [
                $cut->toDateString(), $cut->toDateString(), (int) $cut->format('G'),
            ])->first();

        return (int) ($row->n ?? 0) === 0 ? null : (int) ($row->s ?? 0);
    }

    /** @param  array<int, Period>  $periods */
    private function previousSales(array $periods): ?int
    {
        $rows = $this->rangeQuery('daily_summaries', $periods, 'previousFrom', 'previousTo')
            ->selectRaw('COUNT(*) AS n, SUM(net_sales_amount) AS s')->first();

        return (int) ($rows->n ?? 0) === 0 ? null : (int) ($rows->s ?? 0);
    }

    /** @param  array<int, Period>  $periods */
    private function inHouseAtEndOf(array $periods, string $toField): int
    {
        $q = $this->db->table('daily_summaries')->where(function (Builder $q) use ($periods, $toField): void {
            foreach ($periods as $id => $p) {
                $q->orWhere(fn (Builder $q) => $q->where('branch_id', $id)->where('business_date', $p->{$toField}));
            }
        });

        return (int) $q->sum('stays_in_house_end');
    }

    /**
     * Σ of columns over each branch's own date range.
     *
     * @param  array<int, Period>  $periods
     * @param  array<string, string>  $columns  alias => column
     * @return array<string, int>
     */
    private function sum(string $table, array $periods, string $fromField, string $toField, array $columns): array
    {
        $select = implode(', ', array_map(fn (string $alias, string $col): string => "COALESCE(SUM({$col}), 0) AS `{$alias}`", array_keys($columns), $columns));
        $row = $this->rangeQuery($table, $periods, $fromField, $toField)->selectRaw($select)->first();

        return array_map(fn (string $alias): int => (int) ($row->{$alias} ?? 0), array_combine(array_keys($columns), array_keys($columns)));
    }

    /** @param  array<int, Period>  $periods */
    private function rangeQuery(string $table, array $periods, string $fromField, string $toField): Builder
    {
        return $this->db->table($table)->where(function (Builder $q) use ($periods, $fromField, $toField): void {
            if ($periods === []) {
                $q->whereRaw('1 = 0');
            }

            foreach ($periods as $id => $p) {
                $q->orWhere(fn (Builder $q) => $q->where('branch_id', $id)->whereBetween('business_date', [$p->{$fromField}, $p->{$toField}]));
            }
        });
    }
}
