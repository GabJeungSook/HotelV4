<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\RoomStatus;
use App\Enums\StayState;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\Rotation\RotationCandidate;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Rooms that can take a guest (6, 13, 28, 53, 58; DATABASE_SCHEMA §7).
 *
 * A period is [from, to). What blocks a room for a period:
 * - its open stay (checked in or held), which runs until its expected check-out, or until
 *   now when that has passed (an overdue guest is still in the room);
 * - a reserved stay for that room whose [arrival_at, expected_checkout_at) overlaps.
 * A reservation for "any" room of a type (room_id NULL) blocks no room; it only lowers the
 * count of rooms of the type that can still be reserved (anyReservedCount).
 */
final class RoomQuery
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * Rooms that are available, active and have no open stay, by floor and number. With a type,
     * only that type. With a period, rooms with a reservation overlapping it are left out.
     *
     * @return Collection<int, Room>
     */
    public function free(int $branchId, ?int $roomTypeId = null, ?int $exceptRoomId = null, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Collection
    {
        return Room::query()->withoutGlobalScopes()
            ->with(['roomType' => fn ($q) => $q->withoutGlobalScopes(), 'floor' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('branch_id', $branchId)
            ->where('status', RoomStatus::Available)
            ->where('is_active', true)
            ->when($roomTypeId !== null, fn ($q) => $q->where('room_type_id', $roomTypeId))
            ->when($exceptRoomId !== null, fn ($q) => $q->where('id', '!=', $exceptRoomId))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('stays')->whereColumn('stays.active_room_id', 'rooms.id'))
            ->when($from !== null && $to !== null, fn ($q) => $q->whereNotExists(function (QueryBuilder $s) use ($from, $to): void {
                $s->selectRaw('1')->from('stays')->whereColumn('stays.room_id', 'rooms.id');
                $this->reservedOverlap($s, $from, $to);
            }))
            ->get()
            ->sort(fn (Room $a, Room $b): int => (($a->floor->number ?? 0) <=> ($b->floor->number ?? 0)) ?: strnatcasecmp($a->number, $b->number))
            ->values();
    }

    /** The next free room of a type after a "room just taken" (53). */
    public function nextFree(int $branchId, int $roomTypeId, ?int $exceptRoomId = null): ?Room
    {
        return $this->free($branchId, $roomTypeId, $exceptRoomId, $this->clock->now(), $this->clock->now()->addHour())->first();
    }

    /**
     * Who holds a room now (53: "Rhea Bautista checked in another guest to Room 205").
     *
     * @return array{name: string|null, state: StayState}|null
     */
    public function takenBy(int $roomId): ?array
    {
        $stay = Stay::query()->withoutGlobalScopes()
            ->where('active_room_id', $roomId)
            ->first(['id', 'state', 'checked_in_by', 'source']);

        if ($stay === null) {
            return null;
        }

        return [
            'name' => $stay->checked_in_by === null ? null : User::query()->find($stay->checked_in_by)?->name,
            'state' => $stay->state,
        ];
    }

    /** The first reserved stay of the room overlapping [from, to), other than $exceptStayId. */
    public function reservationOverlapping(int $roomId, CarbonImmutable $from, CarbonImmutable $to, ?int $exceptStayId = null): ?Stay
    {
        /** @var Builder<Stay> $query */
        $query = Stay::query()->withoutGlobalScopes()->where('room_id', $roomId);
        $this->reservedOverlap($query, $from, $to);

        return $query->when($exceptStayId !== null, fn ($q) => $q->where('id', '!=', $exceptStayId))->orderBy('arrival_at')->first();
    }

    /**
     * Whether a room can be reserved for [from, to): active, not under maintenance, its open
     * stay (if any) ends by `from`, and no other reservation of the room overlaps (8.7 step 2).
     */
    public function reservableFor(Room $room, CarbonImmutable $from, CarbonImmutable $to, ?int $exceptStayId = null): bool
    {
        if (! $room->is_active || $room->status === RoomStatus::Maintenance) {
            return false;
        }

        $open = Stay::query()->withoutGlobalScopes()
            ->where('active_room_id', $room->id)
            ->when($exceptStayId !== null, fn ($q) => $q->where('id', '!=', $exceptStayId))
            ->first(['id', 'expected_checkout_at', 'hold_expires_at']);

        if ($open !== null && $this->openStayEnd($open)->greaterThan($from)) {
            return false;
        }

        return $this->reservationOverlapping($room->id, $from, $to, $exceptStayId) === null;
    }

    /**
     * Rooms of a type that can be reserved for [from, to) (58, and the counts on 57).
     *
     * @return Collection<int, Room>
     */
    public function freeForType(int $branchId, int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, ?int $exceptStayId = null): Collection
    {
        $rooms = Room::query()->withoutGlobalScopes()
            ->with(['floor' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('branch_id', $branchId)
            ->where('room_type_id', $roomTypeId)
            ->where('is_active', true)
            ->where('status', '!=', RoomStatus::Maintenance)
            ->get();

        $open = Stay::query()->withoutGlobalScopes()
            ->whereIn('active_room_id', $rooms->pluck('id'))
            ->when($exceptStayId !== null, fn ($q) => $q->where('id', '!=', $exceptStayId))
            ->get(['id', 'room_id', 'expected_checkout_at', 'hold_expires_at'])
            ->keyBy('room_id');

        $reservedQuery = Stay::query()->withoutGlobalScopes()->whereIn('room_id', $rooms->pluck('id'));
        $this->reservedOverlap($reservedQuery, $from, $to);
        $reserved = $reservedQuery->when($exceptStayId !== null, fn ($q) => $q->where('id', '!=', $exceptStayId))->pluck('room_id')->all();

        return $rooms
            ->filter(function (Room $room) use ($open, $reserved, $from): bool {
                $stay = $open->get($room->id);

                return ! in_array($room->id, $reserved, true) && ($stay === null || ! $this->openStayEnd($stay)->greaterThan($from));
            })
            ->sort(fn (Room $a, Room $b): int => strnatcasecmp($a->number, $b->number))
            ->values();
    }

    /** "Any room" reservations of a type overlapping [from, to). */
    public function anyReservedCount(int $branchId, int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to, ?int $exceptStayId = null): int
    {
        $query = Stay::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('room_type_id', $roomTypeId)
            ->whereNull('room_id');
        $this->reservedOverlap($query, $from, $to);

        return $query->when($exceptStayId !== null, fn ($q) => $q->where('id', '!=', $exceptStayId))->count();
    }

    /** Rooms of a type the branch has for sale at all (the "of 24" on 57). */
    public function countOfType(int $branchId, int $roomTypeId): int
    {
        return Room::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('room_type_id', $roomTypeId)
            ->where('is_active', true)
            ->count();
    }

    /**
     * The kiosk's candidates of a type for a stay of [from, to) (28, TRD §4.8), as plain values
     * for RoomRotation, which keeps the eligible ones and orders them.
     *
     * @return list<RotationCandidate>
     */
    public function kioskCandidates(int $branchId, int $roomTypeId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rooms = Room::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('room_type_id', $roomTypeId)
            ->where('is_active', true)
            ->get(['id', 'number', 'status', 'is_kiosk_enabled', 'last_checkout_at']);

        $active = Stay::query()->withoutGlobalScopes()->whereIn('active_room_id', $rooms->pluck('id'))->pluck('active_room_id')->all();
        $reservedQuery = Stay::query()->withoutGlobalScopes()->whereIn('room_id', $rooms->pluck('id'));
        $this->reservedOverlap($reservedQuery, $from, $to);
        $reserved = $reservedQuery->pluck('room_id')->all();

        return array_values($rooms->map(fn (Room $r): RotationCandidate => new RotationCandidate(
            $r->id,
            $r->number,
            $r->status,
            $r->is_kiosk_enabled,
            in_array($r->id, $active, true),
            in_array($r->id, $reserved, true),
            $r->last_checkout_at,
        ))->all());
    }

    /**
     * Narrow a stays query to reserved stays overlapping [from, to).
     *
     * @param  Builder<Stay>|QueryBuilder  $query
     */
    private function reservedOverlap(Builder|QueryBuilder $query, CarbonImmutable $from, CarbonImmutable $to): void
    {
        $query->where('stays.state', StayState::Reserved->value)
            ->where('stays.arrival_at', '<', $to)
            ->where('stays.expected_checkout_at', '>', $from);
    }

    /** When an open stay frees its room: its expected check-out, or now if that has passed. */
    private function openStayEnd(Stay $stay): CarbonImmutable
    {
        $end = $stay->expected_checkout_at ?? $stay->hold_expires_at ?? $this->clock->now();
        $now = $this->clock->now();

        return $end->greaterThan($now) ? $end : $now;
    }
}
