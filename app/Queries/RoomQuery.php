<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\RoomStatus;
use App\Enums\StayState;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Support\Collection;

/** Rooms that can take a guest (6, 13, 53; DATABASE_SCHEMA §7). */
final class RoomQuery
{
    /**
     * Rooms that are available, active and have no open stay, by number. With a type, only
     * that type. Reservations for these rooms come in Phase 7.
     *
     * @return Collection<int, Room>
     */
    public function free(int $branchId, ?int $roomTypeId = null, ?int $exceptRoomId = null): Collection
    {
        return Room::query()->withoutGlobalScopes()
            ->with(['roomType' => fn ($q) => $q->withoutGlobalScopes(), 'floor' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('branch_id', $branchId)
            ->where('status', RoomStatus::Available)
            ->where('is_active', true)
            ->when($roomTypeId !== null, fn ($q) => $q->where('room_type_id', $roomTypeId))
            ->when($exceptRoomId !== null, fn ($q) => $q->where('id', '!=', $exceptRoomId))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('stays')->whereColumn('stays.active_room_id', 'rooms.id'))
            ->get()
            ->sort(fn (Room $a, Room $b): int => (($a->floor->number ?? 0) <=> ($b->floor->number ?? 0)) ?: strnatcasecmp($a->number, $b->number))
            ->values();
    }

    /** The next free room of a type after a "room just taken" (53). */
    public function nextFree(int $branchId, int $roomTypeId, ?int $exceptRoomId = null): ?Room
    {
        return $this->free($branchId, $roomTypeId, $exceptRoomId)->first();
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
}
