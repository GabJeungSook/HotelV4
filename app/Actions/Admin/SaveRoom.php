<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\BranchStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Models\Branch;
use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\RoomStatusService;
use App\Support\DuplicateKey;
use App\Support\Transaction;
use Illuminate\Database\QueryException;

/**
 * Add or edit a room (44; DATABASE_SCHEMA §4.4, APP_FLOW §5.2 and §5.6). One transaction:
 *
 * - the number (letters allowed, such as 3A) is unique within the branch; the floor and the type
 *   must be the branch's own;
 * - a new room starts available (APP_FLOW §5.2); saving the first room of a `setting_up` branch makes it `active`;
 * - the status may change here only between available and maintenance, through
 *   RoomStatusService with reason `admin`. An occupied, uncleaned or cleaning room, or a room
 *   held for a kiosk guest, cannot be changed; nor can such a room change type or be switched off.
 *
 * Writes audit_logs: 'room.created', 'room.updated', 'room.status_changed', 'branch.activated'.
 *
 * @phpstan-type RoomData array{number: string, floor_id: int, room_type_id: int, area: string, is_kiosk_enabled: bool, is_active: bool}
 */
final class SaveRoom
{
    public function __construct(
        private readonly RoomStatusService $statuses,
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {}

    /** @param  RoomData  $data */
    public function handle(User $by, int $branchId, ?int $roomId, array $data, ?RoomStatus $status = null): Room
    {
        try {
            return $this->transaction->run(fn (): Room => $this->save($by, $branchId, $roomId, $data, $status));
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'rooms_branch_number_unique')) {
                throw self::numberTaken($data['number']);
            }

            throw $e;
        }
    }

    /** @param  RoomData  $data */
    private function save(User $by, int $branchId, ?int $roomId, array $data, ?RoomStatus $status): Room
    {
        $room = $roomId === null ? null : Room::query()->withoutGlobalScopes()->where('branch_id', $branchId)->lockForUpdate()->findOrFail($roomId);
        $number = mb_strtoupper(trim($data['number']));

        $taken = Room::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('number', $number)
            ->when($room !== null, fn ($q) => $q->whereKeyNot($room?->id))->exists();

        if ($taken) {
            throw self::numberTaken($number);
        }

        Floor::query()->withoutGlobalScopes()->where('branch_id', $branchId)->findOrFail($data['floor_id']);
        RoomType::query()->withoutGlobalScopes()->where('branch_id', $branchId)->findOrFail($data['room_type_id']);

        $after = [
            'number' => $number,
            'floor_id' => $data['floor_id'],
            'room_type_id' => $data['room_type_id'],
            'area' => trim($data['area']) === '' ? 'Main' : trim($data['area']),
            'is_kiosk_enabled' => $data['is_kiosk_enabled'],
            'is_active' => $data['is_active'],
        ];

        if ($room === null) {
            return $this->create($by, $branchId, $after);
        }

        $before = [
            'number' => $room->number,
            'floor_id' => $room->floor_id,
            'room_type_id' => $room->room_type_id,
            'area' => $room->area,
            'is_kiosk_enabled' => $room->is_kiosk_enabled,
            'is_active' => $room->is_active,
        ];
        [$old, $new] = AuditTrail::diff($before, $after);

        if (isset($new['room_type_id']) || (isset($new['is_active']) && ! $after['is_active'])) {
            $this->refuseWhileInUse($room, isset($new['room_type_id']) ? 'change its type' : 'switch it off');
        }

        $room->update($after);

        if ($new !== []) {
            $this->audit->record($by, $branchId, 'room.updated', 'room', $room->id, $old, $new);
        }

        if ($status !== null && $status !== $room->status) {
            $this->changeStatus($by, $branchId, $room, $status);
        }

        return $room;
    }

    /** @param  array<string, mixed>  $after */
    private function create(User $by, int $branchId, array $after): Room
    {
        $start = RoomStatus::Available;
        $room = Room::query()->create(['branch_id' => $branchId, ...$after, 'status' => $start]);
        $this->audit->record($by, $branchId, 'room.created', 'room', $room->id, null, [...$after, 'status' => $start->value]);

        // The branch row is locked last (DATABASE_SCHEMA §8).
        $branch = Branch::query()->lockForUpdate()->findOrFail($branchId);

        if ($branch->status === BranchStatus::SettingUp) {
            $branch->update(['status' => BranchStatus::Active]);
            $this->audit->record($by, $branchId, 'branch.activated', 'branch', $branchId, ['status' => BranchStatus::SettingUp->value], ['status' => BranchStatus::Active->value]);
        }

        return $room;
    }

    private function changeStatus(User $by, int $branchId, Room $room, RoomStatus $to): void
    {
        $from = $room->status;

        if (in_array($from, [RoomStatus::Occupied, RoomStatus::Uncleaned, RoomStatus::Cleaning], true)) {
            throw ActionRefused::because(match ($from) {
                RoomStatus::Occupied => "Room {$room->number} has a guest in it. Its status changes only at check-out, transfer or cancel.",
                default => "Room {$room->number} is waiting for housekeeping. Its status changes when the cleaning is finished.",
            });
        }

        if (! in_array($to, [RoomStatus::Available, RoomStatus::Maintenance], true)) {
            throw ActionRefused::because('Status can be changed here only between Available and Maintenance.');
        }

        if ($to === RoomStatus::Maintenance) {
            $this->refuseWhileInUse($room, 'put it in Maintenance');
        }

        $this->statuses->transition($room, $to, RoomStatusReason::Admin, null, $by);
        $this->audit->record($by, $branchId, 'room.status_changed', 'room', $room->id, ['status' => $from->value], ['status' => $to->value]);
    }

    /** A room with a held or checked-in stay keeps its type, stays on, and cannot go to maintenance. */
    private function refuseWhileInUse(Room $room, string $what): void
    {
        $stay = Stay::query()->withoutGlobalScopes()->where('active_room_id', $room->id)->first();

        if ($stay !== null || $room->status === RoomStatus::Occupied) {
            $who = $stay?->state === StayState::Held ? 'is held for a kiosk guest' : 'has a guest in it';

            throw ActionRefused::because("Room {$room->number} {$who}. You can {$what} after the guest leaves.");
        }
    }

    private static function numberTaken(string $number): ActionRefused
    {
        return ActionRefused::because("Room {$number} is already in this branch. Room numbers are unique within a branch.");
    }
}
