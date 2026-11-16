<?php

declare(strict_types=1);

namespace App\Queries;

use App\Actions\Reports\CloseDay;
use App\Enums\ShiftStatus;
use App\Models\Branch;
use App\Models\DayClosing;
use App\Models\ShiftSession;
use App\Services\Reports\BusinessDay;
use App\Services\Reports\Totals;
use App\Services\Reports\ZPaper;
use App\Services\ReportService;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Page props of the report screens that are not a report builder of their own: the hub (36),
 * the shifts list, close the day (38b), and the CSV tables of the reports. Every figure here is
 * read from ReportService's builder arrays or from closed shifts' frozen columns; the CSV
 * tables only change the layout of those arrays into rows.
 */
final class ReportScreenQuery
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly BusinessDay $days,
        private readonly CloseDay $closeDay,
    ) {}

    /**
     * The result line of the hub (36): the check of the previous business date, run the same
     * way as the nightly job (APP_FLOW §4.21).
     *
     * @return array{date: string, checked: bool, differences: list<array<string, mixed>>}
     */
    public function check(int $branchId, string $date): array
    {
        $closed = $this->days->shifts($branchId, $date)->where('status', ShiftStatus::Closed)->isNotEmpty();

        return [
            'date' => $date,
            'checked' => $closed,
            'differences' => $closed ? [...$this->reports->storedMismatches($branchId, $date, $date), ...$this->reports->reconcileDay($branchId, $date)] : [],
        ];
    }

    /**
     * The shifts list (APP_FLOW §8.1): shifts by business date, newest first, with their Z
     * numbers and, for a closed shift, its frozen net sales and short or over.
     *
     * @param  array{period?: string|null, desk?: int|null, status?: string|null, from?: string|null, to?: string|null, q?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function shifts(int $branchId, int $page, array $filters = []): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $digits = ltrim((string) preg_replace('/\D/', '', $q), '0');

        /** @var LengthAwarePaginator<int, ShiftSession> $paginator */
        $paginator = ShiftSession::query()->withoutGlobalScopes()
            ->with(['members.user', 'closer'])
            ->where('branch_id', $branchId)
            ->when(($filters['period'] ?? null) !== null, fn ($w) => $w->where('period_name', $filters['period']))
            ->when(($filters['desk'] ?? null) !== null, fn ($w) => $w->whereHas('members', fn ($m) => $m->where('user_id', $filters['desk'])))
            ->when(($filters['from'] ?? null) !== null, fn ($w) => $w->whereDate('business_date', '>=', $filters['from']))
            ->when(($filters['to'] ?? null) !== null, fn ($w) => $w->whereDate('business_date', '<=', $filters['to']))
            ->when(($filters['status'] ?? null) === 'open', fn ($w) => $w->whereIn('status', [ShiftStatus::Open, ShiftStatus::Closing]))
            ->when(($filters['status'] ?? null) === 'closed', fn ($w) => $w->where('status', ShiftStatus::Closed)->where('is_force_closed', false))
            ->when(($filters['status'] ?? null) === 'short', fn ($w) => $w->where('status', ShiftStatus::Closed)->where('variance_amount', '<', 0))
            ->when(($filters['status'] ?? null) === 'force', fn ($w) => $w->where('is_force_closed', true))
            ->when($q !== '', fn ($w) => $w->where(fn ($or) => $or
                ->when($digits !== '', fn ($z) => $z->orWhere('z_no', (int) $digits))
                ->orWhereHas('members.user', fn ($u) => $u->where('name', 'like', '%'.addcslashes($q, '%_\\').'%'))))
            ->orderByDesc('business_date')->orderByDesc('opened_at')
            ->paginate(25, ['*'], 'page', $page);

        return [
            'rows' => array_values(array_map(fn (ShiftSession $s): array => [
                'id' => $s->id,
                'business_date' => $s->business_date->toDateString(),
                'period_name' => $s->period_name,
                'status' => $s->status->value,
                'z_no' => $s->z_no,
                'opened_at' => $s->opened_at->toIso8601String(),
                'closed_at' => $s->closed_at?->toIso8601String(),
                'members' => $s->members->sortBy('joined_at')->map(fn ($m): string => $m->user->name ?? '')->values()->all(),
                // An open shift is listed with no money at all (37a): its figures appear once it closes.
                'net_sales' => $s->status === ShiftStatus::Closed ? Totals::int($s->totals ?? [], 'sales.total') : null,
                'variance' => $s->status === ShiftStatus::Closed ? ($s->variance_amount?->centavos() ?? 0) : null,
                'is_force_closed' => $s->is_force_closed,
            ], $paginator->items())),
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * Close the day (38b): the date's shifts with their Z numbers, net sales and short and over
     * (from the frozen columns), the day number it will get or got, the grand totals, whether it
     * can be closed and why not, and the day paper once it is closed.
     *
     * @return array<string, mixed>
     */
    public function closeDay(int $branchId, string $date): array
    {
        $shifts = $this->days->shifts($branchId, $date);
        $closing = DayClosing::query()->withoutGlobalScopes()->with('closer')->where('branch_id', $branchId)->whereDate('business_date', $date)->first();
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($branchId);
        $previous = DayClosing::query()->withoutGlobalScopes()->where('branch_id', $branchId)->whereDate('business_date', '<', $date)->orderByDesc('business_date')->first();

        $rows = $shifts->map(function (ShiftSession $s): array {
            $variance = $s->variance_amount?->centavos() ?? 0;

            return [
                'id' => $s->id,
                'period_name' => $s->period_name,
                'z_no' => $s->z_no,
                'status' => $s->status->value,
                'members' => $s->members()->with('user')->orderBy('joined_at')->get()->map(fn ($m): string => $m->user->name ?? '')->all(),
                'net_sales' => $s->status === ShiftStatus::Closed ? Totals::int($s->totals ?? [], 'sales.total') : null,
                'short' => $variance < 0 ? $variance : 0,
                'over' => $variance > 0 ? $variance : 0,
                'grand_total_before' => $s->grand_total_before?->centavos(),
                'grand_total_after' => $s->grand_total_after?->centavos(),
            ];
        })->values()->all();

        $closedRows = array_values(array_filter($rows, fn (array $r): bool => $r['z_no'] !== null));
        $before = $closedRows === [] ? null : $closedRows[0]['grand_total_before'];
        $after = $closedRows === [] ? null : $closedRows[count($closedRows) - 1]['grand_total_after'];

        return [
            'date' => $date,
            'shifts' => $rows,
            'total' => [
                'net_sales' => (int) array_sum(array_map(fn (array $r): int => (int) $r['net_sales'], $rows)),
                'short' => (int) array_sum(array_column($rows, 'short')),
                'over' => (int) array_sum(array_column($rows, 'over')),
            ],
            'day_z' => $closing === null ? ReportService::dayNumber($branch->next_day_z_number) : ReportService::dayNumber($closing->day_z_no),
            'closed' => $closing === null ? null : [
                'id' => $closing->id,
                'day_z_no' => $closing->day_z_no,
                'closed_at' => $closing->created_at->toIso8601String(),
                'closed_by' => $closing->closer->name ?? '',
                'shift_z_from' => $closing->shift_z_from,
                'shift_z_to' => $closing->shift_z_to,
                'net_sales' => $closing->net_sales_amount->centavos(),
                'short' => $closing->short_amount->centavos(),
                'over' => $closing->over_amount->centavos(),
                'grand_total_before' => $closing->grand_total_before->centavos(),
                'grand_total_after' => $closing->grand_total_after->centavos(),
            ],
            'grand_total_before' => $closing?->grand_total_before->centavos() ?? $before,
            'grand_total_after' => $closing?->grand_total_after->centavos() ?? $after,
            'reason' => $closing === null ? $this->closeDay->reason($branchId, $date) : null,
            'previous' => $previous === null ? null : ['date' => $previous->business_date->toDateString(), 'day_z' => ReportService::dayNumber($previous->day_z_no)],
            'paper' => $closing === null ? null : $this->reports->dayZ($branchId, $date),
        ];
    }

    /**
     * CSV tables of the shift report (37).
     *
     * @param  array<string, mixed>  $r  ReportService::shift()
     * @return list<array{title: string, columns: list<string>, rows: list<list<string|int>>}>
     */
    public function shiftTables(array $r): array
    {
        /** @var list<array<string, mixed>> $sales */
        $sales = $r['sales'];
        /** @var list<array<string, mixed>> $cash */
        $cash = $r['cash'];
        $a = fn (mixed $c): string => ZPaper::csvAmount((int) $c);

        return [
            ['title' => 'Sales', 'columns' => ['Line', 'Count', 'Amount'], 'rows' => [
                ...array_map(fn (array $l): array => [(string) $l['label'], (int) $l['count'], $a($l['amount'])], $sales),
                ['Sales charged', '', $a($r['sales_total'])],
            ]],
            ['title' => 'Cash', 'columns' => ['Line', 'Count', 'Amount'], 'rows' => [
                ...array_map(fn (array $l): array => [(string) $l['label'], '', $a($l['amount'])], $cash),
                ['Expected', '', $a($r['expected'])],
                ['Counted', '', $r['counted'] === null ? '' : $a($r['counted'])],
                ['Short or over', '', $r['variance'] === null ? '' : $a($r['variance'])],
                ['GCash / card (not in drawer)', '', $a($r['not_in_drawer'])],
            ]],
            ['title' => 'Roll-forwards', 'columns' => ['Box', 'Line', 'Amount'], 'rows' => [
                ...array_map(fn (string $k, int $v): array => ['Guests', $k, $v], array_keys($r['guests']), $r['guests']),
                ...array_map(fn (string $k, int $v): array => ['Key deposits', $k, $a($v)], array_keys($r['deposits']), $r['deposits']),
                ...array_map(fn (string $k, int $v): array => ['Unpaid balances', $k, $a($v)], array_keys($r['unpaid']), $r['unpaid']),
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $r  ReportService::daily()
     * @return list<array{title: string, columns: list<string>, rows: list<list<string|int>>}>
     */
    public function dailyTables(array $r): array
    {
        /** @var list<array<string, mixed>> $columns */
        $columns = $r['columns'];
        /** @var array<string, mixed> $total */
        $total = $r['total'];
        $all = [...$columns, $total];
        $rows = [];

        foreach ($r['rows'] as $row) {
            $rows[] = [(string) $row['label'], ...array_map(fn (array $c): string => ZPaper::csvAmount((int) $c['cells'][$row['key']]), $all)];
        }
        $rows[] = ['Sales', ...array_map(fn (array $c): string => ZPaper::csvAmount((int) $c['total']), $all)];

        return [['title' => "Daily sales {$r['from']} to {$r['to']}", 'columns' => ['Category', ...array_map(fn (array $c): string => (string) $c['label'].(isset($c['sub']) && $c['sub'] ? " {$c['sub']}" : ''), $all)], 'rows' => $rows]];
    }

    /**
     * @param  array<string, mixed>  $r  ReportService::occupancy()
     * @return list<array{title: string, columns: list<string>, rows: list<list<string|int>>}>
     */
    public function occupancyTables(array $r): array
    {
        /** @var list<array<string, mixed>> $types */
        $types = $r['types'];

        return [
            ['title' => "Occupancy {$r['date']}", 'columns' => ['In house at start', 'Checked in', 'Checked out', 'In house at end', 'Cancelled after check-in'], 'rows' => [[(int) $r['start'], (int) $r['in'], (int) $r['out'], (int) $r['end'], (int) $r['cancelled']]]],
            ['title' => 'By room type', 'columns' => ['Room type', 'Rooms', 'Check-ins', 'Extensions', 'Transfers', 'Overstayed'], 'rows' => array_map(
                fn (array $t): array => [(string) $t['name'], (int) $t['rooms'], (int) $t['checkins'], (int) $t['extensions'], (int) $t['transfers'], (int) $t['overstayed']],
                [...$types, $r['total']],
            )],
        ];
    }

    /**
     * @param  array<string, mixed>  $r  ReportService::housekeeping()
     * @return list<array{title: string, columns: list<string>, rows: list<list<string|int>>}>
     */
    public function housekeepingTables(array $r): array
    {
        $local = fn (?string $iso): string => $iso === null ? '' : CarbonImmutable::parse($iso)->setTimezone(Clock::displayTimezone())->format('Y-m-d H:i');

        return [
            ['title' => "Housekeeping {$r['date']}", 'columns' => ['Housekeeper', 'Floors', 'Cleans', 'Average minutes', 'Late'], 'rows' => array_map(
                fn (array $p): array => [(string) $p['name'], (string) $p['floors'], (int) $p['cleans'], (int) $p['average_minutes'], (int) $p['late']],
                $r['people'],
            )],
            ['title' => 'Late rooms', 'columns' => ['Room', 'Guest left', 'Deadline', 'Ready', 'Late by (minutes)', 'Housekeeper'], 'rows' => array_map(
                fn (array $l): array => [(string) $l['room'], $local($l['guest_left']), $local($l['deadline']), $local($l['ready']), (int) $l['late_minutes'], (string) $l['by']],
                $r['late'],
            )],
        ];
    }

    /**
     * @param  array<string, mixed>  $r  ReportService::inventory()
     * @return list<array{title: string, columns: list<string>, rows: list<list<string|int>>}>
     */
    public function inventoryTables(array $r): array
    {
        return [['title' => "Inventory {$r['date']}", 'columns' => ['Product', 'Opening', 'In', 'Sold', 'Wastage', 'Closing', 'Sales'], 'rows' => array_map(
            fn (array $p): array => [(string) $p['name'], (int) $p['opening'], (int) $p['in'], (int) $p['sold'], (int) $p['wastage'], (int) $p['closing'], ZPaper::csvAmount((int) $p['sales'])],
            $r['rows'],
        )]];
    }

    /**
     * @param  array<string, mixed>  $r  ReportService::audit()
     * @return list<array{title: string, columns: list<string>, rows: list<list<string|int>>}>
     */
    public function auditTables(array $r): array
    {
        return [['title' => "Audit trail {$r['date']}", 'columns' => ['Time', 'Action', 'Details', 'Done by', 'Approved by'], 'rows' => array_map(
            fn (array $a): array => [CarbonImmutable::parse((string) $a['at'])->setTimezone(Clock::displayTimezone())->format('Y-m-d H:i'), (string) $a['action'], (string) $a['details'], (string) $a['by'], (string) $a['approved_by']],
            $r['rows'],
        )]];
    }
}
