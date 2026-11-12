<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\RoomStatus;
use App\Enums\StayState;
use App\Models\Branch;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Live state for the front desk's Today (62) and the sign-in facts (1). Counts, room numbers
 * and guest names only: never a peso amount (TRD §4.11, CLAUDE.md rule 9).
 */
final class TodayQuery
{
    public function __construct(private readonly Clock $clock) {}

    /** @return array<string, mixed> */
    public function today(int $branchId): array
    {
        $now = $this->clock->now();
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($branchId);
        $soon = $now->addMinutes($branch->due_soon_minutes);

        $inHouse = Stay::query()->withoutGlobalScopes()
            ->join('rooms', 'rooms.id', '=', 'stays.room_id')
            ->where('stays.branch_id', $branchId)
            ->where('stays.state', StayState::CheckedIn)
            ->get(['stays.id', 'stays.guest_name', 'stays.expected_checkout_at', 'rooms.number as room_number']);

        $leaving = [];
        $overdue = [];
        $dueSoon = [];

        foreach ($inHouse as $stay) {
            $at = $stay->expected_checkout_at;

            if ($at === null || $at->greaterThan($soon)) {
                continue;
            }

            $minutes = intdiv($at->getTimestamp() - $now->getTimestamp(), 60);
            $row = ['stay_id' => $stay->id, 'room' => (string) $stay->getAttribute('room_number'), 'guest' => BoardQuery::shortName($stay->guest_name), 'minutes' => $minutes];
            $leaving[] = $row;
            $minutes < 0 ? $overdue[] = $row : $dueSoon[] = $row;
        }

        // Due soon first (soonest first), then overdue (as in the mockup).
        usort($leaving, static function (array $a, array $b): int {
            $ao = $a['minutes'] < 0;
            $bo = $b['minutes'] < 0;

            return $ao === $bo ? ($ao ? $b['minutes'] <=> $a['minutes'] : $a['minutes'] <=> $b['minutes']) : ($ao ? 1 : -1);
        });

        $unpaid = $this->unpaidRooms($inHouse->pluck('id')->all(), $inHouse->pluck('room_number', 'id')->all());

        $holds = Stay::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('state', StayState::Held)
            ->where('hold_expires_at', '>', $now)
            ->orderBy('hold_expires_at')
            ->get(['id', 'hold_expires_at']);

        [$dayStart, $dayEnd] = $this->localDay();
        $arrivals = Stay::query()->withoutGlobalScopes()
            ->leftJoin('rooms', 'rooms.id', '=', 'stays.room_id')
            ->where('stays.branch_id', $branchId)
            ->where('stays.state', StayState::Reserved)
            ->whereBetween('stays.arrival_at', [$dayStart, $dayEnd])
            ->orderBy('stays.arrival_at')
            ->get(['stays.id', 'stays.guest_name', 'stays.arrival_at', 'stays.room_type_id', 'stays.stay_kind', 'stays.hours', 'stays.nights', 'stays.days', 'rooms.number as room_number']);
        $typeNames = RoomType::query()->withoutGlobalScopes()->where('branch_id', $branchId)->pluck('name', 'id');

        $byStatus = Room::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) AS n')
            ->pluck('n', 'status');
        $vacant = (int) Room::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->where('status', RoomStatus::Available)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('stays')->whereColumn('stays.active_room_id', 'rooms.id'))
            ->count();

        return [
            'counts' => [
                'arriving' => $arrivals->count(),
                'due_soon' => count($dueSoon),
                'overdue' => count($overdue),
                'unpaid' => count($unpaid),
                'holds' => $holds->count(),
            ],
            'due_soon_rooms' => array_map(static fn (array $r): string => $r['room'], $dueSoon),
            'overdue_rooms' => array_map(static fn (array $r): string => $r['room'], $overdue),
            'unpaid_rooms' => array_values($unpaid),
            'unpaid_stay_id' => array_key_first($unpaid),
            'first_hold_expires_at' => $holds->first()?->hold_expires_at?->toIso8601String(),
            'leaving' => $leaving,
            'arriving' => array_values($arrivals->map(fn (Stay $s): array => [
                'id' => $s->id,
                'at' => $s->arrival_at?->toIso8601String(),
                'guest' => $s->guest_name,
                'room' => $s->getAttribute('room_number') === null ? 'Any '.($typeNames[$s->room_type_id] ?? 'room') : (string) $s->getAttribute('room_number'),
                'kind' => $s->stay_kind->value,
                'quantity' => $s->hours ?? $s->nights ?? $s->days,
            ])->all()),
            'rooms' => [
                'vacant' => $vacant,
                'to_clean' => (int) ($byStatus[RoomStatus::Uncleaned->value] ?? 0),
                'cleaning' => (int) ($byStatus[RoomStatus::Cleaning->value] ?? 0),
            ],
            'due_soon_minutes' => $branch->due_soon_minutes,
            'now' => $now->toIso8601String(),
        ];
    }

    /**
     * The four counts under the sign-in greeting (1, TRD §4.11) and the open shift line.
     *
     * @return array{branch: string, available: int, in_house: int, due_soon: int, arriving: int, shift: array{period_name: string, opened_at: string|null}|null}
     */
    public function signInFacts(int $branchId): array
    {
        $now = $this->clock->now();
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($branchId);
        [$dayStart, $dayEnd] = $this->localDay();

        $stays = Stay::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->selectRaw('SUM(state = ?) AS in_house', [StayState::CheckedIn->value])
            ->selectRaw('SUM(state = ? AND expected_checkout_at <= ?) AS due_soon', [StayState::CheckedIn->value, $now->addMinutes($branch->due_soon_minutes)])
            ->selectRaw('SUM(state = ? AND arrival_at BETWEEN ? AND ?) AS arriving', [StayState::Reserved->value, $dayStart, $dayEnd])
            ->first();

        $available = (int) Room::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->where('status', RoomStatus::Available)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('stays')->whereColumn('stays.active_room_id', 'rooms.id'))
            ->count();

        $shift = DB::table('shift_sessions')->where('open_branch_id', $branchId)->first(['period_name', 'opened_at']);

        return [
            'branch' => $branch->name,
            'available' => $available,
            'in_house' => (int) ($stays?->getAttribute('in_house') ?? 0),
            'due_soon' => (int) ($stays?->getAttribute('due_soon') ?? 0),
            'arriving' => (int) ($stays?->getAttribute('arriving') ?? 0),
            'shift' => $shift === null ? null : [
                'period_name' => (string) $shift->period_name,
                'opened_at' => $shift->opened_at === null ? null : CarbonImmutable::parse((string) $shift->opened_at, 'UTC')->toIso8601String(),
            ],
        ];
    }

    /**
     * Rooms of in-house stays with something unpaid, keyed by stay id (no amounts leave here).
     *
     * @param  list<int>  $stayIds
     * @param  array<int, string>  $rooms
     * @return array<int, string>
     */
    private function unpaidRooms(array $stayIds, array $rooms): array
    {
        if ($stayIds === []) {
            return [];
        }

        $due = DB::table('folio_lines')
            ->leftJoinSub(DB::table('payment_allocations')->groupBy('folio_line_id')->selectRaw('folio_line_id, SUM(amount) AS paid'), 'pa', 'pa.folio_line_id', '=', 'folio_lines.id')
            ->whereIn('folio_lines.stay_id', $stayIds)
            ->groupBy('folio_lines.stay_id')
            ->havingRaw('SUM(folio_lines.amount) - COALESCE(SUM(pa.paid), 0) > 0')
            ->pluck('folio_lines.stay_id')
            ->all();

        $out = [];
        foreach ($due as $id) {
            $out[(int) $id] = (string) ($rooms[(int) $id] ?? '');
        }

        asort($out, SORT_NATURAL);

        return $out;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} today in Manila, as UTC instants */
    private function localDay(): array
    {
        $local = $this->clock->local();

        return [$local->startOfDay()->utc(), $local->endOfDay()->utc()];
    }
}
