<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Stats (63) for a date range, from the summary tables only (TRD §4.11, SYSTEM_DESIGN §5.2):
 * totals with the comparison to the same-length period just before (none without earlier
 * data), a series whose grain follows the range (up to 2 days by hour, up to 62 days by day,
 * longer by month), check-ins by weekday and hour, the stay options guests chose, sales by
 * room type, top products, payment methods, occupancy by day and the front desk table.
 */
final class StatsService
{
    private const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    public function __construct(private readonly DatabaseManager $db) {}

    public static function grain(string $from, string $to): string
    {
        $days = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;

        return $days <= 2 ? 'hour' : ($days <= 62 ? 'day' : 'month');
    }

    /**
     * @param  list<int>  $branchIds
     * @return array{from: string, to: string, days: int, previous_from: string, previous_to: string, sales: int, previous_sales: int|null, change: int|null, stays: int, per_day: int, average: int|null, busiest_hour: int|null, busiest_weekday: string|null}
     */
    public function totals(array $branchIds, string $from, string $to): array
    {
        $days = (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
        $prevTo = CarbonImmutable::parse($from)->subDay()->toDateString();
        $prevFrom = CarbonImmutable::parse($from)->subDays($days)->toDateString();

        $now = $this->daily($branchIds, $from, $to)->selectRaw('COALESCE(SUM(net_sales_amount), 0) AS s, COALESCE(SUM(checkins), 0) AS c')->first();
        $prev = $this->daily($branchIds, $prevFrom, $prevTo)->selectRaw('COUNT(*) AS n, COALESCE(SUM(net_sales_amount), 0) AS s')->first();
        $sales = (int) ($now->s ?? 0);
        $stays = (int) ($now->c ?? 0);
        $previous = (int) ($prev->n ?? 0) === 0 ? null : (int) ($prev->s ?? 0);

        [$hour, $weekday] = $this->busiest($branchIds, $from, $to);

        return [
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'previous_from' => $prevFrom,
            'previous_to' => $prevTo,
            'sales' => $sales,
            'previous_sales' => $previous,
            'change' => DashboardService::change($sales, $previous),
            'stays' => $stays,
            'per_day' => (int) round($stays / $days),
            'average' => $stays === 0 ? null : intdiv($sales, $stays),
            'busiest_hour' => $hour,
            'busiest_weekday' => $weekday,
        ];
    }

    /**
     * Net sales and check-ins by hour, day or month.
     *
     * @param  list<int>  $branchIds
     * @return array{grain: string, points: list<array{key: string, sales: int, checkins: int}>}
     */
    public function series(array $branchIds, string $from, string $to): array
    {
        $grain = self::grain($from, $to);
        $points = [];

        if ($grain === 'hour') {
            $rows = $this->table('hourly_sales_summaries', $branchIds, $from, $to)
                ->groupBy('local_date', 'hour')->orderBy('local_date')->orderBy('hour')
                ->selectRaw('local_date, hour, SUM(net_sales_amount) AS s, SUM(checkins) AS c')->get()
                ->keyBy(fn ($r): string => substr((string) $r->local_date, 0, 10).' '.str_pad((string) $r->hour, 2, '0', STR_PAD_LEFT));
            $start = CarbonImmutable::parse($from);
            $end = CarbonImmutable::parse($to)->addDay();
            for ($t = $start; $t->lessThan($end); $t = $t->addHour()) {
                $key = $t->format('Y-m-d H');
                $points[] = ['key' => $t->format('Y-m-d\TH:00'), 'sales' => (int) ($rows[$key]->s ?? 0), 'checkins' => (int) ($rows[$key]->c ?? 0)];
            }

            return ['grain' => $grain, 'points' => $points];
        }

        $rows = $this->daily($branchIds, $from, $to)->groupBy('business_date')
            ->selectRaw('business_date, SUM(net_sales_amount) AS s, SUM(checkins) AS c')->get()
            ->keyBy(fn ($r): string => substr((string) $r->business_date, 0, 10));

        if ($grain === 'day') {
            for ($d = CarbonImmutable::parse($from); $d->lessThanOrEqualTo(CarbonImmutable::parse($to)); $d = $d->addDay()) {
                $key = $d->toDateString();
                $points[] = ['key' => $key, 'sales' => (int) ($rows[$key]->s ?? 0), 'checkins' => (int) ($rows[$key]->c ?? 0)];
            }

            return ['grain' => $grain, 'points' => $points];
        }

        $months = [];
        foreach ($rows as $date => $r) {
            $m = substr((string) $date, 0, 7);
            $months[$m] ??= ['sales' => 0, 'checkins' => 0];
            $months[$m]['sales'] += (int) $r->s;
            $months[$m]['checkins'] += (int) $r->c;
        }
        for ($m = CarbonImmutable::parse($from)->startOfMonth(); $m->lessThanOrEqualTo(CarbonImmutable::parse($to)); $m = $m->addMonth()) {
            $key = $m->format('Y-m');
            $points[] = ['key' => $key, 'sales' => $months[$key]['sales'] ?? 0, 'checkins' => $months[$key]['checkins'] ?? 0];
        }

        return ['grain' => $grain, 'points' => $points];
    }

    /**
     * Check-ins by weekday and local hour (heat grid), from hourly_sales_summaries.
     *
     * @param  list<int>  $branchIds
     * @return array<string, list<int>> weekday => 24 counts
     */
    public function heat(array $branchIds, string $from, string $to): array
    {
        $grid = array_fill_keys(self::WEEKDAYS, array_fill(0, 24, 0));
        $rows = $this->table('hourly_sales_summaries', $branchIds, $from, $to)->where('checkins', '>', 0)
            ->groupBy('local_date', 'hour')->selectRaw('local_date, hour, SUM(checkins) AS c')->get();

        foreach ($rows as $r) {
            $day = CarbonImmutable::parse(substr((string) $r->local_date, 0, 10))->format('D');
            $grid[$day][(int) $r->hour] += (int) $r->c;
        }

        return $grid;
    }

    /**
     * daily_report_lines of one section, largest first.
     *
     * @param  list<int>  $branchIds
     * @return list<array{label: string, quantity: int, amount: int}>
     */
    public function lines(array $branchIds, string $from, string $to, string $section, ?int $limit = null): array
    {
        $order = $section === 'stay_option' ? 'q' : 'a';
        $q = $this->table('daily_report_lines', $branchIds, $from, $to)->where('section', $section)
            ->groupBy('label')->selectRaw('label, SUM(quantity) AS q, SUM(amount) AS a')->orderByDesc($order)->orderBy('label');

        if ($limit !== null) {
            $q->limit($limit);
        }

        return $q->get()->map(fn ($r): array => ['label' => (string) $r->label, 'quantity' => (int) $r->q, 'amount' => (int) $r->a])->values()->all();
    }

    /** @param  list<int>  $branchIds */
    public function otherSales(array $branchIds, string $from, string $to): int
    {
        return (int) $this->daily($branchIds, $from, $to)->sum('other_sales_amount');
    }

    /**
     * @param  list<int>  $branchIds
     * @return list<array{method: string, count: int, amount: int, refunds: int}>
     */
    public function methods(array $branchIds, string $from, string $to): array
    {
        return $this->table('daily_payment_summaries', $branchIds, $from, $to)->groupBy('method')
            ->selectRaw('method, SUM(payment_count) AS c, SUM(amount) AS a, SUM(refund_amount) AS r')->orderByDesc('a')->get()
            ->map(fn ($r): array => ['method' => (string) $r->method, 'count' => (int) $r->c, 'amount' => (int) $r->a, 'refunds' => (int) $r->r])->values()->all();
    }

    /**
     * Guests in house at the end of each day over active rooms.
     *
     * @param  list<int>  $branchIds
     * @return list<array{date: string, in_house: int, rooms: int}>
     */
    public function occupancy(array $branchIds, string $from, string $to): array
    {
        return $this->daily($branchIds, $from, $to)->groupBy('business_date')->orderBy('business_date')
            ->selectRaw('business_date, SUM(stays_in_house_end) AS h, SUM(room_count) AS r')->get()
            ->map(fn ($r): array => ['date' => substr((string) $r->business_date, 0, 10), 'in_house' => (int) $r->h, 'rooms' => (int) $r->r])->values()->all();
    }

    /**
     * The front desk table: each person's payments taken, collected and given back.
     *
     * @param  list<int>  $branchIds
     * @return list<array{user_id: int, name: string, count: int, amount: int, refunds: int}>
     */
    public function frontDesk(array $branchIds, string $from, string $to): array
    {
        $rows = $this->table('daily_payment_summaries', $branchIds, $from, $to)->groupBy('user_id')
            ->selectRaw('user_id, SUM(payment_count) AS c, SUM(amount) AS a, SUM(refund_amount) AS r')->orderByDesc('a')->get();
        $names = User::query()->whereIn('id', $rows->pluck('user_id')->all())->pluck('name', 'id');

        return $rows->map(fn ($r): array => [
            'user_id' => (int) $r->user_id,
            'name' => (string) ($names[$r->user_id] ?? 'Unknown'),
            'count' => (int) $r->c,
            'amount' => (int) $r->a,
            'refunds' => (int) $r->r,
        ])->values()->all();
    }

    /**
     * The hour with the most check-ins, and the weekday with the most check-ins in that hour.
     *
     * @param  list<int>  $branchIds
     * @return array{0: int|null, 1: string|null}
     */
    private function busiest(array $branchIds, string $from, string $to): array
    {
        $heat = $this->heat($branchIds, $from, $to);
        $byHour = array_fill(0, 24, 0);
        foreach ($heat as $counts) {
            foreach ($counts as $h => $c) {
                $byHour[$h] += $c;
            }
        }

        if (max($byHour) === 0) {
            return [null, null];
        }

        $hour = (int) array_search(max($byHour), $byHour, true);
        $best = null;
        foreach ($heat as $day => $counts) {
            if ($best === null || $counts[$hour] > $heat[$best][$hour]) {
                $best = $day;
            }
        }

        return [$hour, $best];
    }

    /** @param  list<int>  $branchIds */
    private function daily(array $branchIds, string $from, string $to): Builder
    {
        return $this->table('daily_summaries', $branchIds, $from, $to);
    }

    /** @param  list<int>  $branchIds */
    private function table(string $table, array $branchIds, string $from, string $to): Builder
    {
        return $this->db->table($table)->whereIn('branch_id', $branchIds)->whereBetween('business_date', [$from, $to]);
    }
}
