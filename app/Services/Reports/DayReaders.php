<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\ApprovalAction;
use App\Enums\CleaningStatus;
use App\Enums\LineKind;
use App\Enums\PaymentMethod;
use App\Enums\ShiftStatus;
use App\Enums\StayKind;
use App\Enums\StayState;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CleaningTask;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\StayRoom;
use App\Models\TransferReason;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The day and period readers of ReportService: daily sales (38), the reconcile check
 * (ReconcileBusinessDay, CloseDay, invariant 7), the owner summary (39), occupancy (40),
 * housekeeping (41), inventory (42) and the audit trail (43).
 *
 * Sales figures are the shifts' frozen totals (an open shift is read live and marked), so a day
 * is the visible sum of its shifts and a week or month the sum of its days.
 *
 * Used by ReportService only; it relies on its clock, stays, cash, days and queries.
 */
trait DayReaders
{
    /** The category rows of 38 (and 37): room is net of its discounts. */
    private const DAILY_ROWS = ['room' => 'Room', 'extension' => 'Extension', 'transfer_difference' => 'Transfer difference', 'food_drink' => 'Food & drinks', 'amenity' => 'Amenities', 'damage' => 'Damages', 'event_hall' => 'Event hall', 'forfeited_deposit' => 'Forfeited deposits'];

    /**
     * Daily sales (38) for a day (one column per shift), a week (one per day) or a month (one per
     * Monday-to-Sunday week inside the month), with the total column last (Gab, 12 Nov).
     *
     * @return array<string, mixed>
     */
    public function daily(int $branchId, string $date, string $range = 'day'): array
    {
        $days = BusinessDay::range($date, $range);
        $shifts = ShiftSession::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereBetween('business_date', [$days[0], end($days)])
            ->orderBy('business_date')->orderBy('opened_at')->orderBy('id')
            ->get();
        $figures = $shifts->mapWithKeys(fn (ShiftSession $s): array => [$s->id => $this->figures($s)]);

        $groups = match ($range) {
            'week' => array_map(fn (string $d): array => [
                'key' => $d, 'label' => CarbonImmutable::parse($d)->format('D j'), 'date' => $d,
                'shifts' => $shifts->filter(fn (ShiftSession $s): bool => $s->business_date->toDateString() === $d),
            ], $days),
            'month' => $this->weeksOf($days, $shifts),
            default => $shifts->map(fn (ShiftSession $s): array => [
                'key' => (string) $s->id,
                'label' => "{$s->period_name} shift",
                'sub' => $s->z_no === null ? 'Open' : self::zNumber($s->z_no),
                'shifts' => collect([$s]),
                'shift_id' => $s->id,
            ])->values()->all(),
        };

        $columns = [];
        foreach ($groups as $g) {
            /** @var Collection<int, ShiftSession> $members */
            $members = $g['shifts'];
            $sum = Totals::sum($members->map(fn (ShiftSession $s): array => $figures[$s->id]['totals'])->values()->all());
            $columns[] = [
                'key' => $g['key'],
                'label' => $g['label'],
                'sub' => $g['sub'] ?? null,
                'date' => $g['date'] ?? null,
                'shift_id' => $g['shift_id'] ?? null,
                'shift_ids' => self::ids($members),
                'open' => $members->contains(fn (ShiftSession $s): bool => $s->status !== ShiftStatus::Closed),
                'cells' => self::dailyCells($sum),
                'total' => Totals::int($sum, 'sales.total'),
            ];
        }

        $all = Totals::sum($figures->map(fn (array $f): array => $f['totals'])->values()->all());
        $total = [
            'key' => 'total',
            'label' => match ($range) {
                'week' => 'Week', 'month' => 'Month', default => 'Day'
            },
            'shift_ids' => self::ids($shifts),
            'cells' => self::dailyCells($all),
            'total' => Totals::int($all, 'sales.total'),
        ];

        $rows = [];
        foreach (self::DAILY_ROWS as $key => $label) {
            if ($key === 'forfeited_deposit' && $total['cells'][$key] === 0) {
                continue;
            }

            $rows[] = ['key' => $key, 'label' => $label];
        }

        $checks = [];
        if (array_sum(array_column($columns, 'total')) !== $total['total']) {
            $checks[] = 'The columns do not add up to the total.';
        }
        foreach ([...$columns, $total] as $col) {
            if (array_sum($col['cells']) !== $col['total']) {
                $checks[] = "{$col['label']}: the categories do not add up to its sales.";
            }
        }

        return [
            'date' => $date,
            'range' => $range,
            'from' => $days[0],
            'to' => end($days),
            'rows' => $rows,
            'columns' => $columns,
            'total' => $total,
            'checks' => $checks,
            'reconcile' => $range === 'day'
                ? ['date' => $date, 'differences' => $this->reconcileDay($branchId, $date), 'checked' => $shifts->isNotEmpty()]
                : ['date' => null, 'differences' => $this->storedMismatches($branchId, $days[0], end($days)), 'checked' => false],
        ];
    }

    /**
     * The nightly check for one business day (APP_FLOW §4.21, invariants 6 and 7): every closed
     * shift of the date is recomputed from the ledger and compared figure by figure with its
     * frozen totals, and the day as a whole is compared with its ledger rows. An empty list
     * means the day agrees. Nothing is corrected.
     *
     * @return list<array{shift_id: int|null, label: string, figure: string, stored: int, recomputed: int}>
     */
    public function reconcileDay(int $branchId, string $date, bool $shiftLevel = true): array
    {
        $shifts = $this->days->shifts($branchId, $date)->where('status', ShiftStatus::Closed)->values();
        $differences = [];

        foreach ($shiftLevel ? $shifts : [] as $shift) {
            $again = $this->recompute($shift);

            foreach ($this->totalsDiff($shift->totals ?? [], $again) as $path => [$stored, $recomputed]) {
                $differences[] = ['shift_id' => $shift->id, 'label' => "{$shift->period_name} ".($shift->z_no === null ? '' : self::zNumber($shift->z_no)), 'figure' => $path, 'stored' => $stored, 'recomputed' => $recomputed];
            }
        }

        // Invariant 7: the sum of the shifts' totals equals the day read straight from the ledger.
        $ids = self::ids($shifts);
        if ($ids !== []) {
            $stored = Totals::sum($shifts->map(fn (ShiftSession $s): array => $s->totals ?? [])->all());
            $paymentIds = Payment::query()->withoutGlobalScopes()->whereIn('shift_session_id', $ids)->pluck('id');
            $ledger = [
                'net_sales' => (int) FolioLine::query()->withoutGlobalScopes()->whereIn('shift_session_id', $ids)->sum('amount'),
                'collected' => (int) PaymentAllocation::query()->whereIn('payment_id', $paymentIds)->sum('amount'),
                'cash_in' => (int) Payment::query()->withoutGlobalScopes()->whereIn('shift_session_id', $ids)->where('method', PaymentMethod::Cash)->sum('amount'),
            ];
            $frozen = [
                'net_sales' => Totals::int($stored, 'sales.total'),
                'collected' => Totals::int($stored, 'unpaid.collected'),
                'cash_in' => Totals::int($stored, 'drawer.cash_in'),
            ];

            foreach ($ledger as $key => $value) {
                if ($frozen[$key] !== $value) {
                    $differences[] = ['shift_id' => null, 'label' => 'Day', 'figure' => "day.{$key}", 'stored' => $frozen[$key], 'recomputed' => $value];
                }
            }
        }

        return $differences;
    }

    /**
     * The figures that differ between two totals arrays. Only numbers and flags are compared:
     * names and labels (a renamed user or discount) are not ledger figures.
     *
     * @param  array<array-key, mixed>  $stored
     * @param  array<array-key, mixed>  $recomputed
     * @return array<string, array{0: int, 1: int}>
     */
    public function totalsDiff(array $stored, array $recomputed): array
    {
        $a = self::flatten($stored);
        $b = self::flatten($recomputed);
        $diff = [];

        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $path) {
            $x = $a[$path] ?? 0;
            $y = $b[$path] ?? 0;

            if ($x !== $y) {
                $diff[$path] = [$x, $y];
            }
        }

        ksort($diff);

        return $diff;
    }

    /**
     * Owner summary (39): per branch, sales, check-ins, occupancy and short and over (never
     * netted) of the closed shifts of the period; the total row is the sum of the branches.
     *
     * @param  list<int>  $branchIds
     * @return array<string, mixed>
     */
    public function summary(array $branchIds, string $from, string $to): array
    {
        $rows = [];
        $days = max(1, (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1);

        foreach (Branch::query()->withoutGlobalScopes()->whereIn('id', $branchIds)->orderBy('name')->get() as $branch) {
            $shifts = ShiftSession::query()->withoutGlobalScopes()
                ->where('branch_id', $branch->id)->where('status', ShiftStatus::Closed)
                ->whereBetween('business_date', [$from, $to])
                ->orderBy('business_date')->orderBy('opened_at')->get();
            $sum = Totals::sum($shifts->map(fn (ShiftSession $s): array => $s->totals ?? [])->all());
            $rooms = Room::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_active', true)->count();
            // Room-nights used: the guests in house at the end of each day (its last shift's forwarded out).
            $inHouse = $shifts->groupBy(fn (ShiftSession $s): string => $s->business_date->toDateString())
                ->sum(fn (Collection $day): int => Totals::int($day->last()->totals ?? [], 'guests.forwarded_out'));

            // Sales split: extras are POS (food, drink, amenities); rooms is everything else so
            // rooms + extras always equals sales. Net is sales after this shift-set's expenses.
            $salesTotal = Totals::int($sum, 'sales.total');
            $extras = Totals::int($sum, 'sales.food_drink') + Totals::int($sum, 'sales.amenity');
            $expenses = Totals::int($sum, 'cash.expenses');

            $rows[] = [
                'branch_id' => $branch->id,
                'name' => $branch->name,
                'sales' => $salesTotal,
                'rooms' => $salesTotal - $extras,
                'extras' => $extras,
                'cash' => Totals::int($sum, 'collected.cash'),
                'gcash' => Totals::int($sum, 'collected.gcash'),
                'card' => Totals::int($sum, 'collected.card'),
                'expenses' => $expenses,
                'net' => $salesTotal - $expenses,
                'checkins' => Totals::int($sum, 'guests.checked_in'),
                'room_days' => $rooms * $days,
                'occupied' => (int) $inHouse,
                'short' => (int) $shifts->sum(fn (ShiftSession $s): int => max(0, -($s->variance_amount?->centavos() ?? 0))),
                'over' => (int) $shifts->sum(fn (ShiftSession $s): int => max(0, $s->variance_amount?->centavos() ?? 0)),
                'shifts' => $shifts->count(),
            ];
        }

        $total = ['name' => 'All'];
        foreach (['sales', 'rooms', 'extras', 'cash', 'gcash', 'card', 'expenses', 'net', 'checkins', 'room_days', 'occupied', 'short', 'over', 'shifts'] as $k) {
            $total[$k] = array_sum(array_column($rows, $k));
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'total' => $total];
    }

    /**
     * What makes up the Rooms and Extras figures of the owner summary (39): read from the same
     * closed shifts, so the parts add up to those figures. Rooms is room and extension charges
     * grouped by room type, then the other room-side kinds (transfers, damage, forfeited, discounts);
     * Extras is food, drink and amenities grouped by item.
     *
     * @param  list<int>  $branchIds
     * @return array{rooms: list<array{name: string, amount: int}>, extras: list<array{name: string, amount: int}>}
     */
    public function salesBreakdown(array $branchIds, string $from, string $to): array
    {
        $shiftIds = ShiftSession::query()->withoutGlobalScopes()
            ->whereIn('branch_id', $branchIds)->where('status', ShiftStatus::Closed)
            ->whereBetween('business_date', [$from, $to])->pluck('id');

        $lines = FolioLine::query()->withoutGlobalScopes()
            ->whereIn('shift_session_id', $shiftIds)
            ->whereNull('reversal_of_id')->whereDoesntHave('reversal')
            ->get(['id', 'kind', 'stay_id', 'description', 'amount']);

        $sum = fn (Collection $rows): int => (int) $rows->sum(fn (FolioLine $l): int => $l->amount?->centavos() ?? 0);

        $roomLines = $lines->whereIn('kind', [LineKind::Room, LineKind::Extension]);
        $typeName = RoomType::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->pluck('name', 'id');
        $stayType = Stay::query()->withoutGlobalScopes()
            ->whereIn('id', $roomLines->pluck('stay_id')->filter()->unique()->all())->pluck('room_type_id', 'id');

        $rooms = $roomLines
            ->groupBy(function (FolioLine $l) use ($typeName, $stayType): string {
                $typeId = $l->stay_id === null ? null : ($stayType[$l->stay_id] ?? null);

                return $typeId === null ? 'Room' : ($typeName[$typeId] ?? 'Room');
            })
            ->map(fn (Collection $g, string $name): array => ['name' => $name, 'amount' => $sum($g)])
            ->sortByDesc('amount')->values();

        foreach ([[LineKind::TransferDifference, 'Transfers'], [LineKind::Damage, 'Damage charges'], [LineKind::ForfeitedDeposit, 'Forfeited deposits'], [LineKind::Discount, 'Discounts']] as [$kind, $label]) {
            $amount = $sum($lines->where('kind', $kind));
            if ($amount !== 0) {
                $rooms->push(['name' => $label, 'amount' => $amount]);
            }
        }

        $extras = $lines->whereIn('kind', [LineKind::FoodDrink, LineKind::Amenity])
            ->groupBy('description')
            ->map(fn (Collection $g, string $name): array => ['name' => $name, 'amount' => $sum($g)])
            ->sortByDesc('amount')->values();

        return ['rooms' => $rooms->values()->all(), 'extras' => $extras->all()];
    }

    /**
     * Occupancy and guests (40) for a business day. Counts come from the stays' actual check-in
     * and check-out times inside the day's window, so start + in − out = end by construction;
     * a stay cancelled after check-in was never a guest and is counted on its own line.
     *
     * @return array<string, mixed>
     */
    public function occupancy(int $branchId, string $date): array
    {
        [$start, $end] = $this->days->window($branchId, $date);
        $stays = Stay::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereNotNull('checked_in_at')
            ->where('checked_in_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('checked_out_at')->orWhere('checked_out_at', '>=', $start))
            ->get();
        $cancelled = $stays->where('state', StayState::Cancelled);
        $guests = $stays->reject(fn (Stay $s): bool => $s->state === StayState::Cancelled);

        $atStart = $guests->filter(fn (Stay $s): bool => $s->checked_in_at->lessThan($start));
        $in = $guests->filter(fn (Stay $s): bool => $s->checked_in_at->greaterThanOrEqualTo($start));
        $out = $guests->filter(fn (Stay $s): bool => $s->checked_out_at !== null && $s->checked_out_at->lessThan($end));
        $atEnd = $guests->filter(fn (Stay $s): bool => $s->checked_out_at === null || $s->checked_out_at->greaterThanOrEqualTo($end));

        $lineStays = fn (LineKind $kind): Collection => FolioLine::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)->where('kind', $kind)->whereNull('reversal_of_id')->whereDoesntHave('reversal')
            ->whereBetween('occurred_at', [$start, $end])->whereNotNull('stay_id')->get(['id', 'stay_id']);
        $extensions = $lineStays(LineKind::Extension);
        $transfers = StayRoom::query()->whereNotNull('transfer_reason_id')->whereBetween('from_at', [$start, $end])
            ->whereIn('stay_id', $guests->pluck('id'))->get(['stay_id']);
        $overstayed = $out->filter(fn (Stay $s): bool => $s->expected_checkout_at !== null && $s->checked_out_at->greaterThan($s->expected_checkout_at));
        $byStay = $guests->keyBy('id');

        $types = [];
        foreach (RoomType::query()->withoutGlobalScopes()->where('branch_id', $branchId)->orderBy('id')->get() as $type) {
            $ofType = fn (Collection $rows, string $col = 'room_type_id'): int => $rows->filter(fn ($r): bool => ($col === 'stay' ? ($byStay[$r->stay_id]->room_type_id ?? null) : $r->room_type_id) === $type->id)->count();
            $types[] = [
                'id' => $type->id,
                'name' => $type->name,
                'rooms' => Room::query()->withoutGlobalScopes()->where('room_type_id', $type->id)->where('is_active', true)->count(),
                'checkins' => $ofType($in),
                'extensions' => $ofType($extensions, 'stay'),
                'transfers' => $ofType($transfers, 'stay'),
                'overstayed' => $ofType($overstayed),
            ];
        }

        $total = ['name' => 'Total'];
        foreach (['rooms', 'checkins', 'extensions', 'transfers', 'overstayed'] as $k) {
            $total[$k] = array_sum(array_column($types, $k));
        }

        $list = fn (Collection $rows): array => array_values($rows->sortBy('checked_in_at')->map(fn (Stay $s): array => [
            'id' => $s->id, 'code' => $s->code, 'guest_name' => $s->guest_name, 'room_type_id' => $s->room_type_id,
            'checked_in_at' => $s->checked_in_at->toIso8601String(), 'checked_out_at' => $s->checked_out_at?->toIso8601String(),
            'expected_checkout_at' => $s->expected_checkout_at?->toIso8601String(), 'state' => $s->state->value,
        ])->all());

        return [
            'date' => $date,
            'window' => [$start->toIso8601String(), $end->toIso8601String()],
            'start' => $atStart->count(),
            'in' => $in->count(),
            'out' => $out->count(),
            'end' => $atEnd->count(),
            'cancelled' => $cancelled->count(),
            'types' => $types,
            'total' => $total,
            'lists' => ['start' => $list($atStart), 'in' => $list($in), 'out' => $list($out), 'end' => $list($atEnd), 'cancelled' => $list($cancelled), 'overstayed' => $list($overstayed)],
            'checks' => $atStart->count() + $in->count() - $out->count() === $atEnd->count() ? [] : ['In house at start + checked in − checked out does not equal in house at end.'],
        ];
    }

    /**
     * Guest movements (new report): a per-guest log of arrivals, departures, extensions and
     * transfers over a day, week or month, newest first, with the mix of arrivals by room type.
     * Filterable by movement kind. Read-only from the ledger and stays.
     *
     * @return array<string, mixed>
     */
    public function guestMovements(int $branchId, string $date, string $range = 'day', string $type = 'all'): array
    {
        $days = BusinessDay::range($date, $range);
        [$start] = $this->days->window($branchId, $days[0]);
        [, $end] = $this->days->window($branchId, end($days));

        $names = User::query()->withoutGlobalScopes()->where('branch_id', $branchId)->pluck('name', 'id');
        $typeNames = RoomType::query()->withoutGlobalScopes()->where('branch_id', $branchId)->orderBy('id')->pluck('name', 'id');
        $reasons = TransferReason::query()->withoutGlobalScopes()->where('branch_id', $branchId)->pluck('name', 'id');
        $roomNo = Room::query()->withoutGlobalScopes()->where('branch_id', $branchId)->pluck('number', 'id');

        $rows = [];
        $mix = [];

        $stays = Stay::query()->withoutGlobalScopes()->where('branch_id', $branchId)->whereNotNull('checked_in_at')
            ->where(fn ($q) => $q->whereBetween('checked_in_at', [$start, $end])->orWhereBetween('checked_out_at', [$start, $end]))
            ->get();
        $stayById = $stays->keyBy('id');

        $departedIds = [];
        foreach ($stays as $s) {
            if ($s->state !== StayState::Cancelled && $s->checked_in_at->greaterThanOrEqualTo($start) && $s->checked_in_at->lessThan($end)) {
                $rows[] = self::movement($s->checked_in_at, 'arrival', $s, $roomNo, $typeNames, self::stayOption($s).' · '.$s->source->label(), (string) ($names[$s->checked_in_by] ?? ''), $s->quoted_amount?->centavos() ?? 0);
                $mix[$s->room_type_id] = ($mix[$s->room_type_id] ?? 0) + 1;
            }
            if ($s->checked_out_at !== null && $s->checked_out_at->greaterThanOrEqualTo($start) && $s->checked_out_at->lessThan($end)) {
                $departedIds[] = $s->id;
            }
        }

        $departTotals = FolioLine::query()->withoutGlobalScopes()->whereIn('stay_id', $departedIds)->whereNull('reversal_of_id')
            ->selectRaw('stay_id, SUM(amount) as total')->groupBy('stay_id')->pluck('total', 'stay_id');
        foreach ($departedIds as $id) {
            $s = $stayById[$id];
            $rows[] = self::movement($s->checked_out_at, 'departure', $s, $roomNo, $typeNames, 'Checked out', (string) ($names[$s->checked_out_by] ?? ''), (int) ($departTotals[$id] ?? 0));
        }

        $extLines = FolioLine::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('kind', LineKind::Extension)
            ->whereNull('reversal_of_id')->whereDoesntHave('reversal')->whereBetween('occurred_at', [$start, $end])->whereNotNull('stay_id')->get();
        $extStays = Stay::query()->withoutGlobalScopes()->whereIn('id', $extLines->pluck('stay_id')->unique())->get()->keyBy('id');
        foreach ($extLines as $l) {
            $s = $extStays[$l->stay_id] ?? null;
            if ($s !== null) {
                $rows[] = self::movement($l->occurred_at, 'extension', $s, $roomNo, $typeNames, ($l->description ?? '') !== '' ? $l->description : 'Extension', (string) ($names[$l->created_by] ?? ''), $l->amount?->centavos() ?? 0);
            }
        }

        $transfers = StayRoom::query()->whereNotNull('transfer_reason_id')->whereBetween('from_at', [$start, $end])->get();
        $tStays = Stay::query()->withoutGlobalScopes()->whereIn('id', $transfers->pluck('stay_id')->unique())->where('branch_id', $branchId)->get()->keyBy('id');
        foreach ($transfers as $t) {
            $s = $tStays[$t->stay_id] ?? null;
            if ($s !== null) {
                $detail = 'Moved to Room '.($roomNo[$t->room_id] ?? '?').(isset($reasons[$t->transfer_reason_id]) ? ' · '.$reasons[$t->transfer_reason_id] : '');
                $rows[] = self::movement($t->from_at, 'transfer', $s, $roomNo, $typeNames, $detail, '', 0, (string) ($roomNo[$t->room_id] ?? ''));
            }
        }

        usort($rows, static fn (array $a, array $b): int => $b['sort'] <=> $a['sort']);

        $counts = ['arrival' => 0, 'departure' => 0, 'extension' => 0, 'transfer' => 0];
        foreach ($rows as $r) {
            $counts[$r['kind']]++;
        }

        if ($type !== 'all') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['kind'] === $type));
        }
        $rows = array_map(static function (array $r): array {
            unset($r['sort']);

            return $r;
        }, $rows);

        $mixRows = [];
        foreach ($typeNames as $id => $name) {
            $mixRows[] = ['type' => $name, 'count' => $mix[$id] ?? 0];
        }

        return [
            'date' => $date,
            'range' => $range,
            'from' => $days[0],
            'to' => end($days),
            'type' => $type,
            'counts' => $counts,
            'rows' => $rows,
            'mix' => $mixRows,
        ];
    }

    /** The stay-option label for a movement (e.g. "6 hrs", "overnight", "3 days"). */
    private static function stayOption(Stay $s): string
    {
        return match ($s->stay_kind) {
            StayKind::Hourly => ($s->hours ?? 0).' hrs',
            StayKind::Overnight => ($s->nights ?? 1) === 1 ? 'overnight' : ($s->nights ?? 1).' nights',
            StayKind::LongStay => ($s->days ?? 1).' '.(($s->days ?? 1) === 1 ? 'day' : 'days'),
        };
    }

    /**
     * One movement row for the guest-movements log.
     *
     * @param  Collection<int, string>  $roomNo
     * @param  Collection<int, string>  $typeNames
     * @return array<string, mixed>
     */
    private static function movement(CarbonImmutable $at, string $kind, Stay $stay, Collection $roomNo, Collection $typeNames, string $details, string $by, int $amount, ?string $room = null): array
    {
        return [
            'at' => $at->toIso8601String(),
            'sort' => $at->getTimestamp(),
            'kind' => $kind,
            'guest' => $stay->guest_name,
            'code' => $stay->code,
            'room' => $room ?? (string) ($roomNo[$stay->room_id] ?? ''),
            'room_type' => (string) ($typeNames[$stay->room_type_id] ?? ''),
            'details' => $details,
            'by' => $by,
            'amount' => $amount,
        ];
    }

    /**
     * Housekeeping (41): cleans, average time and late rooms per housekeeper, for a business day
     * or one shift. A clean belongs to the window it was finished in.
     *
     * @return array<string, mixed>
     */
    public function housekeeping(int $branchId, string $date, ?ShiftSession $shift = null): array
    {
        [$start, $end] = $shift === null ? $this->days->window($branchId, $date) : [$shift->opened_at, $shift->closed_at ?? $this->clock->now()];
        $tasks = CleaningTask::query()->withoutGlobalScopes()
            ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'cleaner', 'stay' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('branch_id', $branchId)
            ->where('status', CleaningStatus::Done)
            ->whereBetween('finished_at', [$start, $end])
            ->orderBy('finished_at')
            ->get();

        $people = [];
        foreach ($tasks->groupBy('cleaned_by') as $userId => $done) {
            $user = $done->first()?->cleaner;
            $minutes = $done->map(fn (CleaningTask $t): int => $t->started_at === null ? 0 : intdiv($t->finished_at->getTimestamp() - $t->started_at->getTimestamp(), 60));
            $people[] = [
                'user_id' => (int) $userId,
                'name' => $user->name ?? '',
                'floors' => $user === null ? '' : $this->floorNumbers($user),
                'cleans' => $done->count(),
                'average_minutes' => $done->isEmpty() ? 0 : intdiv((int) $minutes->sum() + intdiv($done->count(), 2), $done->count()),
                'late' => $done->where('is_late', true)->count(),
                'rooms' => $done->map(fn (CleaningTask $t): array => [
                    'room' => $t->room->number ?? '',
                    'ready' => $t->finished_at?->toIso8601String(),
                    'minutes' => $t->started_at === null ? 0 : intdiv($t->finished_at->getTimestamp() - $t->started_at->getTimestamp(), 60),
                    'late' => (bool) $t->is_late,
                ])->values()->all(),
            ];
        }
        usort($people, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        $late = array_values($tasks->where('is_late', true)->map(fn (CleaningTask $t): array => [
            'id' => $t->id,
            'room' => $t->room->number ?? '',
            'guest_left' => $t->queued_at->toIso8601String(),
            'deadline' => $t->due_at->toIso8601String(),
            'ready' => $t->finished_at?->toIso8601String(),
            'late_minutes' => intdiv($t->finished_at->getTimestamp() - $t->due_at->getTimestamp(), 60),
            'by' => $t->cleaner->name ?? '',
        ])->all());

        return [
            'date' => $date,
            'shift_id' => $shift?->id,
            'window' => [$start->toIso8601String(), $end->toIso8601String()],
            'people' => $people,
            'total' => ['cleans' => $tasks->count(), 'late' => $tasks->where('is_late', true)->count()],
            'late' => $late,
        ];
    }

    /**
     * Inventory (42): stock per product for a business day, with the sales value; closing =
     * opening + in − sold − wastage, and one day's closing is the next day's opening.
     *
     * @return array<string, mixed>
     */
    public function inventory(int $branchId, string $date): array
    {
        $shifts = $this->days->shifts($branchId, $date);
        [$start, $end] = $this->days->window($branchId, $date, $shifts);
        $rows = $this->queries->stockForDay($branchId, self::ids($shifts), $start, $end);

        $sales = FolioLine::query()->withoutGlobalScopes()
            ->whereIn('shift_session_id', self::ids($shifts))->whereNotNull('product_id')
            ->groupBy('product_id')->selectRaw('product_id, SUM(amount) AS total')->pluck('total', 'product_id');

        $rows = array_map(fn (array $r): array => [...$r, 'sales' => (int) ($sales[$r['product_id']] ?? 0)], $rows);
        $checks = [];
        foreach ($rows as $r) {
            if ($r['closing'] !== $r['opening'] + $r['in'] - $r['sold'] - $r['wastage']) {
                $checks[] = "{$r['name']}: opening + in − sold − wastage does not equal closing.";
            }
        }

        return ['date' => $date, 'has_shifts' => $shifts->isNotEmpty(), 'rows' => $rows, 'checks' => $checks];
    }

    /**
     * Audit trail (43): every approval and audited action of the business day, with who did it,
     * who approved it and the reason. Filtered by type and by person.
     *
     * @return array<string, mixed>
     */
    public function audit(int $branchId, string $date, string $type = 'all', ?int $userId = null): array
    {
        [$start, $end] = $this->days->window($branchId, $date);
        $types = [
            'voids' => [ApprovalAction::VoidLine, ApprovalAction::VoidCashMovement, ApprovalAction::VoidSale],
            'cancels' => [ApprovalAction::CancelStay, ApprovalAction::CancelReservation, ApprovalAction::RefundNoShow],
            'transfers' => [ApprovalAction::Transfer],
            'shift' => [ApprovalAction::ForceCloseShift, ApprovalAction::CloseWithoutHandover, ApprovalAction::ChangeShiftPeriod, ApprovalAction::ApproveVariance],
        ];
        $names = User::query()->pluck('name', 'id');
        $rows = [];

        $approvals = Approval::query()->withoutGlobalScopes()->where('branch_id', $branchId)->whereBetween('created_at', [$start, $end])
            ->when(isset($types[$type]), fn ($q) => $q->whereIn('action', $types[$type]))
            ->when($userId !== null, fn ($q) => $q->where('requested_by', $userId))
            ->get();
        foreach ($approvals as $a) {
            $group = collect($types)->search(fn (array $actions): bool => in_array($a->action, $actions, true)) ?: 'other';
            $rows[] = [
                'id' => 'approval-'.$a->id,
                'at' => $a->created_at->toIso8601String(),
                'type' => $group,
                'action' => $a->action->label(),
                'details' => trim(($a->summary ?? '').($a->reason ? " · {$a->reason}" : ''), ' ·'),
                'by' => $names[$a->requested_by] ?? '',
                'approved_by' => $names[$a->approved_by] ?? '',
            ];
        }

        if ($type === 'all' || $type === 'shift') {
            $logs = AuditLog::query()->withoutGlobalScopes()->where('branch_id', $branchId)->whereBetween('created_at', [$start, $end])
                ->whereIn('action', ['shift.closed', 'day.closed', 'reconcile.mismatch', 'invariant.failed', 'pin.unlocked'])
                ->when($userId !== null, fn ($q) => $q->where('actor_id', $userId))
                ->get();
            $shifts = ShiftSession::query()->withoutGlobalScopes()->whereIn('id', $logs->where('subject_type', 'shift_session')->pluck('subject_id'))->get()->keyBy('id');

            foreach ($logs as $log) {
                $shift = $log->subject_type === 'shift_session' ? $shifts[$log->subject_id] ?? null : null;
                $variance = $shift?->variance_amount?->centavos() ?? 0;
                $rows[] = [
                    'id' => 'log-'.$log->id,
                    'at' => $log->created_at->toIso8601String(),
                    'type' => in_array($log->action, ['shift.closed', 'day.closed'], true) ? 'shift' : 'other',
                    'action' => match ($log->action) {
                        'shift.closed' => 'Shift closed', 'day.closed' => 'Day closed', 'reconcile.mismatch' => 'Check failed',
                        'invariant.failed' => 'Check failed', default => 'PIN unlocked',
                    },
                    'details' => match ($log->action) {
                        'shift.closed' => ($shift === null ? 'Shift closed' : "{$shift->period_name} shift closed".($shift->z_no === null ? '' : ' · '.self::zNumber($shift->z_no)))
                            .($variance === 0 ? '' : ($variance < 0 ? ' · short ' : ' · over ').Money::ofCentavos(abs($variance))->format()),
                        'day.closed' => self::dayLog($log->after_json),
                        'reconcile.mismatch' => self::mismatchLog($log->after_json),
                        'invariant.failed' => 'A nightly data check did not pass — see the attention list.',
                        'pin.unlocked' => 'A computer’s PIN entry was unlocked.',
                        default => self::scalarLog($log->after_json),
                    },
                    'by' => $names[$log->actor_id] ?? '',
                    'approved_by' => '',
                ];
            }
        }

        // Setup changes (44–48, 55–56, 49): rates, prices, settings, users, rooms and lists (mockup 45:
        // "Price changes are logged in the audit trail"). Passwords and PINs are never in these rows.
        if ($type === 'all' || $type === 'other') {
            $prefixes = ['room.', 'floor.', 'room_type.', 'rate.', 'extension_rate.', 'discount.', 'settings.', 'shift_definition.', 'transfer_reason.',
                'expense_category.', 'reason.', 'user.', 'product.', 'branch.', 'cleaning.reset', 'owner.'];
            $setup = AuditLog::query()->withoutGlobalScopes()->where('branch_id', $branchId)->whereBetween('created_at', [$start, $end])
                ->where(fn ($q) => collect($prefixes)->each(fn (string $p) => $q->orWhere('action', 'like', $p.'%')))
                ->when($userId !== null, fn ($q) => $q->where('actor_id', $userId))
                ->get();

            foreach ($setup as $log) {
                $after = is_array($log->after_json) ? $log->after_json : [];
                $before = is_array($log->before_json) ? $log->before_json : [];
                $parts = [];
                foreach ($after as $key => $value) {
                    if (is_scalar($value) || $value === null) {
                        $old = $before[$key] ?? null;
                        $changed = array_key_exists($key, $before) && (is_scalar($old) || $old === null) && $old !== $value;
                        $parts[] = self::auditKey($key).' '.($changed ? self::auditValue($key, $old).' → ' : '').self::auditValue($key, $value);
                    }
                }
                $rows[] = [
                    'id' => 'log-'.$log->id,
                    'at' => $log->created_at->toIso8601String(),
                    'type' => 'other',
                    'action' => 'Setup · '.str_replace(['.', '_'], [' ', ' '], $log->action),
                    'details' => mb_substr(implode(' · ', $parts), 0, 240),
                    'by' => $names[$log->actor_id] ?? '',
                    'approved_by' => '',
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['at'], $b['at']));

        return [
            'date' => $date,
            'type' => $type,
            'user_id' => $userId,
            'rows' => $rows,
            'people' => array_values(User::query()->withoutGlobalScopes()->where('branch_id', $branchId)->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all()),
        ];
    }

    /**
     * The reconcile.mismatch rows the nightly job wrote for business dates in a range.
     *
     * @return list<array{shift_id: int|null, label: string, figure: string, stored: int, recomputed: int}>
     */
    public function storedMismatches(int $branchId, string $from, string $to): array
    {
        $out = [];

        foreach (AuditLog::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('action', 'reconcile.mismatch')->orderBy('id')->get() as $log) {
            $after = $log->after_json ?? [];
            $date = is_string($after['date'] ?? null) ? $after['date'] : null;

            if ($date === null || $date < $from || $date > $to) {
                continue;
            }

            foreach (is_array($after['differences'] ?? null) ? $after['differences'] : [] as $d) {
                if (is_array($d)) {
                    $out[] = [
                        'shift_id' => isset($d['shift_id']) ? (int) $d['shift_id'] : null,
                        'label' => $date.' · '.($d['label'] ?? ''),
                        'figure' => (string) ($d['figure'] ?? ''),
                        'stored' => (int) ($d['stored'] ?? 0),
                        'recomputed' => (int) ($d['recomputed'] ?? 0),
                    ];
                }
            }
        }

        return $out;
    }

    /** A readable label for an audit field key (replaces the raw column name). */
    private static function auditKey(string $key): string
    {
        return match ($key) {
            'floor_room_rates_enabled' => 'Floor & room rates',
            'event_hall_enabled' => 'Event halls',
            'kiosk_enabled' => 'Kiosk',
            'reservations_enabled' => 'Reservations',
            'pos_enabled' => 'POS and stock',
            'housekeeping_enabled' => 'Housekeeping',
            'vat_enabled' => 'VAT',
            'hourly_enabled' => 'Hourly stays',
            'overnight_enabled' => 'Overnight stays',
            'long_stay_enabled' => 'Long stay',
            'stay_kind' => 'stay',
            default => str_replace('_', ' ', $key),
        };
    }

    /** A readable value for an audit field: money formatted, booleans as on/off, no raw quotes. */
    private static function auditValue(string $key, mixed $value): string
    {
        if (str_ends_with($key, '_enabled')) {
            return $value ? 'on' : 'off';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if ($value === null) {
            return 'none';
        }

        if (is_numeric($value) && ($key === 'amount' || str_ends_with($key, '_amount') || str_contains($key, 'price'))) {
            return Money::ofCentavos((int) $value)->format();
        }

        return match ((string) $value) {
            'floor_id' => 'floor',
            'room_id' => 'room',
            'room_type_id' => 'room type',
            default => (string) $value,
        };
    }

    /** "D-0054 · net ₱3,070.00" for a day.closed audit row. */
    private static function dayLog(mixed $json): string
    {
        $a = is_array($json) ? $json : [];

        return self::dayNumber((int) ($a['day_z_no'] ?? 0)).' · net '.Money::ofCentavos((int) ($a['net_sales'] ?? 0))->format();
    }

    /** "2 figures do not agree with the ledger" for a reconcile.mismatch audit row. */
    private static function mismatchLog(mixed $json): string
    {
        $a = is_array($json) ? $json : [];
        $n = is_array($a['differences'] ?? null) ? count($a['differences']) : 0;

        return $n === 1 ? '1 figure does not agree with the ledger' : "{$n} figures do not agree with the ledger";
    }

    /** A plain fallback for any other logged action: readable key/values, never raw JSON. */
    private static function scalarLog(mixed $json): string
    {
        $a = is_array($json) ? $json : [];
        $parts = [];

        foreach ($a as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $parts[] = self::auditKey((string) $key).' '.self::auditValue((string) $key, $value);
            }
        }

        return mb_substr(implode(' · ', $parts), 0, 240);
    }

    /**
     * @param  array<string, mixed>  $sum
     * @return array<string, int>
     */
    private static function dailyCells(array $sum): array
    {
        $cells = [];
        foreach (array_keys(self::DAILY_ROWS) as $key) {
            $cells[$key] = $key === 'room' ? Totals::int($sum, 'sales.room') + Totals::int($sum, 'sales.discount') : Totals::int($sum, "sales.{$key}");
        }

        return $cells;
    }

    /**
     * The Monday-to-Sunday weeks of a month, clipped to the month (38, month view).
     *
     * @param  list<string>  $days
     * @param  Collection<int, ShiftSession>  $shifts
     * @return list<array<string, mixed>>
     */
    private function weeksOf(array $days, Collection $shifts): array
    {
        $weeks = [];
        foreach ($days as $d) {
            $monday = CarbonImmutable::parse($d)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            $weeks[$monday][] = $d;
        }

        return array_values(array_map(fn (array $ds): array => [
            'key' => $ds[0],
            'label' => CarbonImmutable::parse($ds[0])->format('j').'–'.CarbonImmutable::parse(end($ds))->format('j M'),
            'date' => $ds[0],
            'shifts' => $shifts->filter(fn (ShiftSession $s): bool => in_array($s->business_date->toDateString(), $ds, true)),
        ], $weeks));
    }

    private function floorNumbers(User $user): string
    {
        $numbers = DB::table('floor_user')->join('floors', 'floors.id', '=', 'floor_user.floor_id')
            ->where('floor_user.user_id', $user->id)->orderBy('floors.number')->pluck('floors.number')->map(fn ($n): int => (int) $n)->all();

        if ($numbers === []) {
            return '—';
        }

        $consecutive = count($numbers) > 1 && end($numbers) - $numbers[0] === count($numbers) - 1;

        return $consecutive ? $numbers[0].'–'.end($numbers) : implode(', ', $numbers);
    }

    /**
     * Numbers and flags of a nested array by dot path; lists of people and discounts keyed by
     * their id or name, so a reordering is not a difference.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<string, int>
     */
    private static function flatten(array $value, string $prefix = ''): array
    {
        $out = [];

        foreach ($value as $key => $v) {
            if (is_array($v) && array_is_list($v)) {
                foreach ($v as $item) {
                    if (is_array($item)) {
                        $id = (string) ($item['user_id'] ?? $item['name'] ?? '');
                        $out += self::flatten($item, "{$prefix}{$key}[{$id}].");
                    }
                }

                continue;
            }

            if (is_array($v)) {
                $out += self::flatten($v, "{$prefix}{$key}.");
            } elseif (is_int($v)) {
                $out["{$prefix}{$key}"] = $v;
            } elseif (is_bool($v)) {
                $out["{$prefix}{$key}"] = $v ? 1 : 0;
            }
        }

        return $out;
    }
}
