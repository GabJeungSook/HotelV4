<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CleaningStatus;
use App\Enums\CleaningTrigger;
use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Exceptions\RoomTransitionNotAllowed;
use App\Models\Branch;
use App\Models\CleaningTask;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\Stay;
use App\Models\User;
use App\Support\Clock;

/**
 * The only writer of rooms.status (CLAUDE.md rule 10). Locks the room, checks the move
 * against APP_FLOW §5.2 and writes a room_status_logs row.
 */
final class RoomStatusService
{
    /**
     * Allowed moves: from → to → the reasons that may cause it (APP_FLOW §5.2).
     *
     * @var array<string, array<string, list<RoomStatusReason>>>
     */
    private const MOVES = [
        'available' => [
            'occupied' => [RoomStatusReason::CheckIn],
            'maintenance' => [RoomStatusReason::Admin],
        ],
        'occupied' => [
            'uncleaned' => [RoomStatusReason::CheckOut, RoomStatusReason::TransferOut],
            'maintenance' => [RoomStatusReason::TransferOut],
            'available' => [RoomStatusReason::Cancel],
        ],
        'uncleaned' => [
            'cleaning' => [RoomStatusReason::CleanStart],
        ],
        'cleaning' => [
            'available' => [RoomStatusReason::CleanFinish],
            'maintenance' => [RoomStatusReason::CleanFinish],
            'uncleaned' => [RoomStatusReason::Admin],
        ],
        'maintenance' => [
            'available' => [RoomStatusReason::Admin],
        ],
    ];

    public function __construct(private readonly Clock $clock) {}

    /**
     * Whether a move is allowed. With housekeeping switched off, a check-out or transfer-out
     * sends the room straight to available (TRD §4.9).
     */
    public function allows(RoomStatus $from, RoomStatus $to, RoomStatusReason $reason, bool $housekeepingEnabled = true): bool
    {
        if (! $housekeepingEnabled && $from === RoomStatus::Occupied && $to === RoomStatus::Available
            && in_array($reason, [RoomStatusReason::CheckOut, RoomStatusReason::TransferOut], true)) {
            return true;
        }

        return in_array($reason, self::MOVES[$from->value][$to->value] ?? [], true);
    }

    public function transition(Room $room, RoomStatus $to, RoomStatusReason $reason, ?Stay $stay = null, ?User $by = null): Room
    {
        $locked = Room::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($room->id);
        $from = $locked->status;
        $housekeeping = Branch::query()->whereKey($locked->branch_id)->value('housekeeping_enabled');

        if (! $this->allows($from, $to, $reason, (bool) $housekeeping)) {
            throw RoomTransitionNotAllowed::move($locked->number, $from, $to, $reason);
        }

        $locked->update(['status' => $to]);

        RoomStatusLog::query()->create([
            'room_id' => $locked->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'stay_id' => $stay?->id,
            'user_id' => $by?->id,
            'created_at' => $this->clock->now(),
        ]);

        $room->forceFill(['status' => $to])->syncOriginalAttribute('status');

        return $room;
    }

    /**
     * A stay leaves a room (check-out or transfer out). The room goes to maintenance when asked,
     * else to uncleaned with one queued cleaning task due cleaning_deadline_minutes from now;
     * with housekeeping switched off it goes straight to available and no task is made (TRD §4.9).
     */
    public function vacate(Room $room, Stay $stay, RoomStatusReason $reason, CleaningTrigger $trigger, bool $toMaintenance = false, ?User $by = null): RoomStatus
    {
        $branch = Branch::query()->findOrFail($room->branch_id);

        if ($toMaintenance) {
            $this->transition($room, RoomStatus::Maintenance, $reason, $stay, $by);

            return RoomStatus::Maintenance;
        }

        if (! $branch->housekeeping_enabled) {
            $this->transition($room, RoomStatus::Available, $reason, $stay, $by);

            return RoomStatus::Available;
        }

        $this->transition($room, RoomStatus::Uncleaned, $reason, $stay, $by);
        $now = $this->clock->now();

        CleaningTask::query()->create([
            'branch_id' => $room->branch_id,
            'room_id' => $room->id,
            'stay_id' => $stay->id,
            'trigger_kind' => $trigger,
            'status' => CleaningStatus::Queued,
            'queued_at' => $now,
            'due_at' => $now->addMinutes($branch->cleaning_deadline_minutes),
        ]);

        return RoomStatus::Uncleaned;
    }
}
