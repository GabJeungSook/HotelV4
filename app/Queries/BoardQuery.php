<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CleaningStatus;
use App\Enums\RoomStatus;
use App\Enums\StayState;
use App\Models\Branch;
use App\Models\CleaningTask;
use App\Models\Floor;
use App\Models\Room;
use App\Models\Stay;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The room board (4, SYSTEM_DESIGN §6.1): one row per room, grouped by floor, with the display
 * status worked out here from the room's status and its open stay, so the board can never
 * show a room as free while a guest is checked in. Six queries whatever the number of rooms.
 */
final class BoardQuery
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @return array{floors: list<array{label: string, rooms: list<array<string, mixed>>}>, total: int, floorOptions: list<array{value: string, label: string}>, now: string}
     */
    public function forBranch(int $branchId, ?string $search = null, ?string $floor = null): array
    {
        $now = $this->clock->now();
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($branchId);
        $floors = Floor::query()->withoutGlobalScopes()->where('branch_id', $branchId)->orderBy('number')->get();
        $rooms = Room::query()->withoutGlobalScopes()
            ->join('room_types', 'room_types.id', '=', 'rooms.room_type_id')
            ->where('rooms.branch_id', $branchId)
            ->where('rooms.is_active', true)
            ->get(['rooms.*', 'room_types.name as type_name']);

        $stays = Stay::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereIn('state', [StayState::CheckedIn, StayState::Held])
            ->whereNotNull('active_room_id')
            ->get(['id', 'room_id', 'state', 'guest_name', 'expected_checkout_at', 'hold_expires_at'])
            ->keyBy('room_id');

        $checkedIn = $stays->filter(fn (Stay $s): bool => $s->state === StayState::CheckedIn)->pluck('id')->all();
        $balances = $this->balances($checkedIn);

        $tasks = CleaningTask::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereIn('status', [CleaningStatus::Queued, CleaningStatus::InProgress])
            ->get(['room_id', 'status', 'queued_at', 'started_at'])
            ->keyBy('room_id');

        $search = $search === null ? '' : mb_strtolower(trim($search));
        $floorFilter = $floor === null || $floor === '' ? null : $floor;
        $grouped = [];

        foreach ($rooms as $room) {
            $stay = $stays->get($room->id);
            $row = $this->row($room, $stay, $tasks->get($room->id), (string) $room->getAttribute('type_name'), $balances, $branch, $now);

            if ($search !== '' && ! $this->matches($room, $stay, $search)) {
                continue;
            }

            $grouped[$room->floor_id][] = $row;
        }

        $out = [];
        $options = [];

        foreach ($floors as $f) {
            $options[] = ['value' => (string) $f->id, 'label' => $f->label];

            if ($floorFilter !== null && (string) $f->id !== $floorFilter) {
                continue;
            }

            $list = $grouped[$f->id] ?? [];
            usort($list, static fn (array $a, array $b): int => strnatcasecmp((string) $a['number'], (string) $b['number']));

            if ($list !== []) {
                $out[] = ['label' => "Floor {$f->number}", 'rooms' => $list];
            }
        }

        return ['floors' => $out, 'total' => $rooms->count(), 'floorOptions' => $options, 'now' => $now->toIso8601String()];
    }

    /**
     * @param  array<int, int>  $balances
     * @return array<string, mixed>
     */
    private function row(Room $room, ?Stay $stay, ?CleaningTask $task, string $type, array $balances, Branch $branch, CarbonImmutable $now): array
    {
        $row = [
            'id' => $room->id,
            'number' => $room->number,
            'type' => $type,
            'status' => 'vacant',
            'guest' => null,
            'stayId' => null,
            'dueOutAt' => null,
            'minutes' => null,
            'holdSeconds' => null,
            'balance' => null,
        ];

        if ($stay !== null && $stay->state === StayState::CheckedIn) {
            $left = $stay->expected_checkout_at === null ? null : intdiv($stay->expected_checkout_at->getTimestamp() - $now->getTimestamp(), 60);
            $status = match (true) {
                $left !== null && $left < 0 => 'overdue',
                $left !== null && $left <= $branch->due_soon_minutes => 'due_out',
                default => 'occupied',
            };
            $balance = $balances[$stay->id] ?? 0;

            return [...$row, 'status' => $status, 'guest' => self::shortName($stay->guest_name), 'stayId' => $stay->id,
                'dueOutAt' => $stay->expected_checkout_at?->toIso8601String(), 'minutes' => $left, 'balance' => $balance > 0 ? $balance : null];
        }

        if ($stay !== null && $stay->state === StayState::Held) {
            $seconds = $stay->hold_expires_at === null ? 0 : max(0, $stay->hold_expires_at->getTimestamp() - $now->getTimestamp());

            return [...$row, 'status' => 'held', 'guest' => self::shortName($stay->guest_name), 'stayId' => $stay->id, 'holdSeconds' => $seconds];
        }

        return match ($room->status) {
            RoomStatus::Maintenance => [...$row, 'status' => 'blocked'],
            RoomStatus::Cleaning => [...$row, 'status' => 'cleaning', 'minutes' => $task?->started_at === null ? null : intdiv($now->getTimestamp() - $task->started_at->getTimestamp(), 60)],
            RoomStatus::Uncleaned => [...$row, 'status' => 'to_clean', 'minutes' => $task?->queued_at === null ? null : intdiv($now->getTimestamp() - $task->queued_at->getTimestamp(), 60)],
            default => $row,
        };
    }

    /** Search covers room numbers and names of checked-in guests only (APP_FLOW §6 case 58). */
    private function matches(Room $room, ?Stay $stay, string $search): bool
    {
        if (str_contains(mb_strtolower($room->number), $search)) {
            return true;
        }

        return $stay !== null && $stay->state === StayState::CheckedIn && str_contains(mb_strtolower($stay->guest_name), $search);
    }

    /**
     * Unpaid balance per checked-in stay in centavos (Σ lines − Σ allocations).
     *
     * @param  list<int>  $stayIds
     * @return array<int, int>
     */
    private function balances(array $stayIds): array
    {
        if ($stayIds === []) {
            return [];
        }

        $rows = DB::table('folio_lines')
            ->leftJoinSub(
                DB::table('payment_allocations')->groupBy('folio_line_id')->selectRaw('folio_line_id, SUM(amount) AS paid'),
                'pa',
                'pa.folio_line_id',
                '=',
                'folio_lines.id',
            )
            ->whereIn('folio_lines.stay_id', $stayIds)
            ->groupBy('folio_lines.stay_id')
            ->selectRaw('folio_lines.stay_id AS stay_id, SUM(folio_lines.amount) - COALESCE(SUM(pa.paid), 0) AS due')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->stay_id] = (int) $r->due;
        }

        return $out;
    }

    /** "Rico Manalastas" → "R. Manalastas" (the board and Today show initial + surname). */
    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        if (count($parts) < 2) {
            return trim($name);
        }

        $last = array_pop($parts);

        return mb_substr($parts[0], 0, 1).'. '.$last;
    }
}
