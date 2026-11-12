<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\LineKind;
use App\Enums\ShiftStatus;
use App\Models\CashMovement;
use App\Models\CleaningTask;
use App\Models\Floor;
use App\Models\FolioLine;
use App\Models\Room;
use App\Models\ShiftForwardedStay;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\StayRoom;
use Illuminate\Support\Collection;

/**
 * The other tabs of the Z-report (APP_FLOW §4.22 step 4): rooms by floor (37c), cleaning chart,
 * housekeeping log, stocks and expenses, each for one shift. "Rooms by floor" reads the shift's
 * own folio lines, stays and forwarded rows; its gross total equals net sales on 37b, which is
 * checked here and shown as a red line if it ever did not.
 *
 * Used by ReportService only; it relies on its clock, stays, cash, days and queries.
 */
trait ZTabs
{
    /** The floor × category rows of 37c, in the mockup's order. */
    private const FLOOR_ROWS = ['room' => 'Room', 'extend' => 'Extend', 'transfer' => 'Transfer difference', 'foods' => 'Foods', 'drinks' => 'Drinks', 'misc' => 'Miscellaneous'];

    /**
     * Rooms by floor (37c): sales by floor and category with the "No room" column, deposits
     * held at close, then one chart per floor with a row per stay in house during the shift.
     *
     * @return array<string, mixed>
     */
    public function zRooms(ShiftSession $shift): array
    {
        $f = $this->figures($shift);
        $end = $shift->closed_at ?? $this->clock->now();
        $floors = Floor::query()->withoutGlobalScopes()->where('branch_id', $shift->branch_id)->orderBy('number')->get();
        $rooms = Room::query()->withoutGlobalScopes()->where('branch_id', $shift->branch_id)->orderBy('number')->get()->keyBy('id');
        $lines = FolioLine::query()->withoutGlobalScopes()->with(['product' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('shift_session_id', $shift->id)->orderBy('id')->get();
        $stays = Stay::query()->withoutGlobalScopes()->whereIn('id', $lines->pluck('stay_id')->filter()->unique()->values())->get()->keyBy('id');

        // Deposits held at close: the forwarded-out rows (closed) or the guests in house now (preview).
        $held = $shift->status === ShiftStatus::Closed
            ? ShiftForwardedStay::query()->where('shift_session_id', $shift->id)->get()
                ->map(fn (ShiftForwardedStay $r): array => ['stay_id' => $r->stay_id, 'room_id' => $r->room_id, 'key' => $r->key_deposit_held?->centavos() ?? 0])
            : collect($this->stays->inHouse($shift->branch_id))
                ->map(fn (array $r): array => ['stay_id' => $r['stay_id'], 'room_id' => $r['room_id'], 'key' => $r['key_deposit_held']->centavos()]);

        $table = [];
        foreach (array_keys(self::FLOOR_ROWS) as $row) {
            $table[$row] = array_fill_keys([...$floors->pluck('id')->map(fn ($id): string => (string) $id)->all(), 'none'], 0);
        }

        foreach ($lines as $line) {
            $roomId = $line->room_id ?? ($line->stay_id === null ? null : $stays[$line->stay_id]?->room_id);
            $column = $line->stay_id === null && $line->room_id === null ? 'none' : (string) ($rooms[$roomId]->floor_id ?? 'none');
            $table[self::floorRow($line)][$column] += $line->amount->centavos();
        }

        $deposits = array_fill_keys(array_keys($table['room']), 0);
        foreach ($held as $h) {
            $deposits[(string) ($rooms[$h['room_id']]->floor_id ?? 'none')] += $h['key'];
        }

        $columns = [...$floors->map(fn (Floor $fl): array => ['key' => (string) $fl->id, 'label' => 'Floor '.$fl->number])->all(), ['key' => 'none', 'label' => 'No room']];
        $rows = [];
        foreach (self::FLOOR_ROWS as $key => $label) {
            $rows[] = ['key' => $key, 'label' => $label, 'cells' => $table[$key], 'total' => array_sum($table[$key])];
        }
        $gross = array_fill_keys(array_keys($table['room']), 0);
        foreach ($table as $cells) {
            foreach ($cells as $col => $amount) {
                $gross[$col] += $amount;
            }
        }

        $checks = [];
        $net = Totals::int($f['totals'], 'sales.total');
        if (array_sum($gross) !== $net) {
            $checks[] = 'The rooms-by-floor total ('.ZPaper::csvAmount(array_sum($gross)).') differs from net sales on the Z-report ('.ZPaper::csvAmount($net).').';
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'gross' => ['cells' => $gross, 'total' => array_sum($gross)],
            'deposits' => ['cells' => $deposits, 'total' => array_sum($deposits)],
            'charts' => $this->roomCharts($shift, $floors, $rooms, $lines, $held->keyBy('stay_id')->all(), $end),
            'net_sales' => $net,
            'checks' => $checks,
        ];
    }

    /**
     * Cleaning chart: one row per cleaning task queued or finished during the shift.
     *
     * @return list<array<string, mixed>>
     */
    public function zCleaning(ShiftSession $shift): array
    {
        $end = $shift->closed_at ?? $this->clock->now();

        return array_values(CleaningTask::query()->withoutGlobalScopes()
            ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'cleaner'])
            ->where('branch_id', $shift->branch_id)
            ->where(fn ($q) => $q->whereBetween('queued_at', [$shift->opened_at, $end])->orWhereBetween('finished_at', [$shift->opened_at, $end]))
            ->orderBy('queued_at')->orderBy('id')
            ->get()
            ->map(fn (CleaningTask $t): array => [
                'id' => $t->id,
                'room' => $t->room->number ?? '',
                'status' => $t->status->value,
                'queued_at' => $t->queued_at->toIso8601String(),
                'due_at' => $t->due_at->toIso8601String(),
                'started_at' => $t->started_at?->toIso8601String(),
                'finished_at' => $t->finished_at?->toIso8601String(),
                'by' => $t->cleaner->name ?? null,
                'is_late' => $t->is_late,
                'result' => $t->result?->value,
            ])->all());
    }

    /**
     * Expenses tab: the shift's cash entries, voided ones included and marked.
     *
     * @return list<array<string, mixed>>
     */
    public function zExpenses(ShiftSession $shift): array
    {
        return array_values(CashMovement::query()->withoutGlobalScopes()
            ->with(['creator', 'expenseCategory' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('shift_session_id', $shift->id)
            ->orderBy('occurred_at')->orderBy('id')
            ->get()
            ->map(fn (CashMovement $m): array => [
                'id' => $m->id,
                'occurred_at' => $m->occurred_at->toIso8601String(),
                'kind' => $m->kind->value,
                'category' => $m->expenseCategory?->name,
                'note' => $m->note,
                'by' => $m->creator->name ?? '',
                'amount' => $m->amount?->centavos() ?? 0,
                'voided' => $m->voided_at !== null,
                'void_reason' => $m->void_reason,
            ])->all());
    }

    private static function floorRow(FolioLine $line): string
    {
        return match ($line->kind) {
            LineKind::Room, LineKind::Discount => 'room',
            LineKind::Extension => 'extend',
            LineKind::TransferDifference => 'transfer',
            LineKind::ForfeitedDeposit => 'misc',
            default => $line->product?->report_group->value ?? 'misc',
        };
    }

    /**
     * One chart per floor: every room, one row per stay in it during the shift (forwarded in ∪
     * checked in), only what was charged this shift on that row; FWD for a guest forwarded in,
     * EXT for an extension made this shift; a room nobody used is "Vacant all shift".
     *
     * @param  Collection<int, Floor>  $floors
     * @param  Collection<int, Room>  $rooms
     * @param  Collection<int, FolioLine>  $lines
     * @param  array<int, array{stay_id: int, room_id: int, key: int}>  $held
     * @return list<array<string, mixed>>
     */
    private function roomCharts(ShiftSession $shift, Collection $floors, Collection $rooms, Collection $lines, array $held, \DateTimeInterface $end): array
    {
        $spans = StayRoom::query()
            ->whereIn('room_id', $rooms->keys())
            ->where('from_at', '<', $end)
            ->where(fn ($q) => $q->whereNull('to_at')->orWhere('to_at', '>', $shift->opened_at))
            ->orderBy('from_at')->orderBy('id')
            ->get();
        $stays = Stay::query()->withoutGlobalScopes()->whereIn('id', $spans->pluck('stay_id')->merge($lines->pluck('stay_id'))->filter()->unique()->values())->get()->keyBy('id');

        /** @var array<string, array<string, mixed>> $rows keyed "stay:room" */
        $rows = [];
        $row = function (int $stayId, int $roomId, ?StayRoom $span) use (&$rows, $stays, $shift, $end): void {
            $key = "{$stayId}:{$roomId}";

            if (isset($rows[$key])) {
                return;
            }

            $stay = $stays[$stayId] ?? null;
            $out = $span->to_at ?? $stay?->checked_out_at;
            $rows[$key] = [
                'stay_id' => $stayId,
                'room_id' => $roomId,
                'code' => $stay?->code,
                'guest' => self::guestShort($stay->guest_name ?? ''),
                'in' => ($span->from_at ?? $stay?->checked_in_at)?->toIso8601String(),
                'out' => $out !== null && $out->lessThanOrEqualTo($end) ? $out->toIso8601String() : null,
                'forwarded' => $stay?->checked_in_at !== null && $stay->checked_in_at->lessThan($shift->opened_at),
                'extended' => false,
                'room_charge' => 0, 'ext' => 0, 'foods' => 0, 'drinks' => 0, 'misc' => 0,
                'deposit' => 0,
            ];
        };

        foreach ($spans as $span) {
            if (($stays[$span->stay_id] ?? null)?->checked_in_at !== null) {
                $row($span->stay_id, $span->room_id, $span);
            }
        }

        foreach ($lines as $line) {
            if ($line->stay_id === null) {
                continue;
            }

            $roomId = $line->room_id ?? $stays[$line->stay_id]?->room_id;

            if ($roomId === null) {
                continue;
            }

            $row($line->stay_id, $roomId, null);
            $key = "{$line->stay_id}:{$roomId}";
            $column = match (self::floorRow($line)) {
                'room', 'transfer' => 'room_charge',
                'extend' => 'ext',
                default => self::floorRow($line),
            };
            $rows[$key][$column] += $line->amount->centavos();

            if ($line->kind === LineKind::Extension && $line->reversal_of_id === null) {
                $rows[$key]['extended'] = true;
            }
        }

        foreach ($held as $stayId => $h) {
            $key = "{$stayId}:{$h['room_id']}";

            if (isset($rows[$key])) {
                $rows[$key]['deposit'] = $h['key'];
            }
        }

        $byRoom = collect($rows)->groupBy('room_id');
        $charts = [];

        foreach ($floors as $floor) {
            $list = [];

            foreach ($rooms->where('floor_id', $floor->id)->sortBy('number', SORT_NATURAL) as $room) {
                $stayRows = $byRoom->get($room->id);

                if ($stayRows === null || $stayRows->isEmpty()) {
                    $list[] = ['room' => $room->number, 'vacant' => true];

                    continue;
                }

                foreach ($stayRows->sortBy('in') as $r) {
                    $list[] = ['room' => $room->number, 'vacant' => false, ...$r];
                }
            }

            $charts[] = ['floor' => 'Floor '.$floor->number, 'rows' => $list, 'room_count' => $rooms->where('floor_id', $floor->id)->count()];
        }

        return $charts;
    }

    /** "Sonia Reyes" → "S. Reyes" (37c). */
    private static function guestShort(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return count($parts) < 2 ? ($parts[0] ?? '') : mb_substr($parts[0], 0, 1).'. '.end($parts);
    }
}
