<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SaveFloor;
use App\Actions\Admin\SaveRoom;
use App\Actions\Admin\SaveRoomType;
use App\Enums\RoomStatus;
use App\Http\Requests\Admin\FloorRequest;
use App\Http\Requests\Admin\RoomRequest;
use App\Http\Requests\Admin\RoomTypeRequest;
use App\Queries\AdminSetupQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Rooms (44) with the floors and room types tabs (APP_FLOW §2, §5.2). */
final class RoomController extends AdminController
{
    private const TABS = ['rooms', 'floors', 'types'];

    public function index(Request $request, AdminSetupQuery $query): Response
    {
        $tab = $request->string('tab')->toString();

        return Inertia::render('Admin/Rooms', [
            'tab' => in_array($tab, self::TABS, true) ? $tab : 'rooms',
            ...$query->rooms($this->branch()),
        ]);
    }

    public function store(RoomRequest $request, SaveRoom $save): RedirectResponse
    {
        $room = $save->handle($this->user($request), $this->branchId(), null, $this->roomData($request));

        return $this->saved("Room {$room->number} added.");
    }

    public function update(RoomRequest $request, SaveRoom $save, int $room): RedirectResponse
    {
        $status = $request->filled('status') ? RoomStatus::from($request->string('status')->toString()) : null;
        $saved = $save->handle($this->user($request), $this->branchId(), $room, $this->roomData($request), $status);

        return $this->saved("Room {$saved->number} saved.");
    }

    public function storeFloor(FloorRequest $request, SaveFloor $save): RedirectResponse
    {
        $floor = $save->handle($this->user($request), $this->branchId(), null, $request->integer('number'), $request->string('label')->toString());

        return $this->saved("Floor {$floor->label} added.");
    }

    public function updateFloor(FloorRequest $request, SaveFloor $save, int $floor): RedirectResponse
    {
        $saved = $save->handle($this->user($request), $this->branchId(), $floor, $request->integer('number'), $request->string('label')->toString());

        return $this->saved("Floor {$saved->label} saved.");
    }

    public function storeType(RoomTypeRequest $request, SaveRoomType $save): RedirectResponse
    {
        $type = $save->handle($this->user($request), $this->branchId(), null, $request->string('name')->toString(), $this->description($request), $request->boolean('is_active'));

        return $this->saved("{$type->name} added.");
    }

    public function updateType(RoomTypeRequest $request, SaveRoomType $save, int $type): RedirectResponse
    {
        $saved = $save->handle($this->user($request), $this->branchId(), $type, $request->string('name')->toString(), $this->description($request), $request->boolean('is_active'));

        return $this->saved("{$saved->name} saved.");
    }

    /** @return array{number: string, floor_id: int, room_type_id: int, area: string, is_kiosk_enabled: bool, is_active: bool} */
    private function roomData(RoomRequest $request): array
    {
        return [
            'number' => $request->string('number')->toString(),
            'floor_id' => $request->integer('floor_id'),
            'room_type_id' => $request->integer('room_type_id'),
            'area' => $request->string('area')->toString(),
            'is_kiosk_enabled' => $request->boolean('is_kiosk_enabled'),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    private function description(Request $request): ?string
    {
        return $request->filled('description') ? $request->string('description')->toString() : null;
    }
}
