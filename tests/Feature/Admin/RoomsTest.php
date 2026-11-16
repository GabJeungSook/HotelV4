<?php

declare(strict_types=1);

use App\Actions\Admin\SaveFloor;
use App\Actions\Admin\SaveRoom;
use App\Actions\Admin\SaveRoomType;
use App\Enums\BranchStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Exceptions\ActionRefused;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\RoomType;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Rooms (44): SaveRoom, SaveFloor, SaveRoomType and RoomController — APP_FLOW §5.2 (status only
| Available ↔ Maintenance, through RoomStatusService with reason admin) and §5.6 (the first room
| makes a setting-up branch active).
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    $this->admin = Hotel::user('ramil.ortega');
    $this->branchId = Hotel::branch()->id;
    $this->floor = Floor::query()->where('branch_id', $this->branchId)->where('number', 1)->firstOrFail();
    $this->type = RoomType::query()->where('branch_id', $this->branchId)->where('name', 'Standard')->firstOrFail();
    $this->room = fn (array $over = []): array => [
        'number' => '150', 'floor_id' => $this->floor->id, 'room_type_id' => $this->type->id, 'area' => 'Main', 'is_kiosk_enabled' => true, 'is_active' => true, ...$over,
    ];
    $this->save = fn (?int $id, array $data, ?RoomStatus $status = null) => app(SaveRoom::class)->handle($this->admin, $this->branchId, $id, $data, $status);
});

it('adds a room with letters in its number, available, with an audit row', function (): void {
    $room = ($this->save)(null, ($this->room)(['number' => '3c']));

    expect($room->number)->toBe('3C')
        ->and($room->status)->toBe(RoomStatus::Available)
        ->and($room->branch_id)->toBe($this->branchId);

    $log = AuditLog::query()->where('action', 'room.created')->sole();
    expect($log->subject_id)->toBe($room->id)
        ->and($log->actor_id)->toBe($this->admin->id)
        ->and($log->branch_id)->toBe($this->branchId)
        ->and($log->after_json['number'])->toBe('3C');
});

it('refuses a room number already used in the branch, but allows it in another branch', function (): void {
    expect(fn () => ($this->save)(null, ($this->room)(['number' => '205'])))
        ->toThrow(ActionRefused::class, 'Room 205 is already in this branch.');

    $other = Branch::factory()->settingUp()->create();
    $floor = Floor::factory()->create(['branch_id' => $other->id]);
    $type = RoomType::factory()->create(['branch_id' => $other->id]);
    $room = app(SaveRoom::class)->handle($this->admin, $other->id, null, ($this->room)(['number' => '205', 'floor_id' => $floor->id, 'room_type_id' => $type->id]));

    expect($room->branch_id)->toBe($other->id)
        ->and(AuditLog::query()->where('action', 'room.created')->count())->toBe(1);
});

it('makes a setting-up branch active when its first room is saved', function (): void {
    $branch = Branch::factory()->settingUp()->create();
    $floor = Floor::factory()->create(['branch_id' => $branch->id]);
    $type = RoomType::factory()->create(['branch_id' => $branch->id]);

    app(SaveRoom::class)->handle($this->admin, $branch->id, null, ($this->room)(['number' => '101', 'floor_id' => $floor->id, 'room_type_id' => $type->id]));

    expect($branch->fresh()?->status)->toBe(BranchStatus::Active)
        ->and(AuditLog::query()->where('action', 'branch.activated')->where('branch_id', $branch->id)->count())->toBe(1);

    app(SaveRoom::class)->handle($this->admin, $branch->id, null, ($this->room)(['number' => '102', 'floor_id' => $floor->id, 'room_type_id' => $type->id]));
    expect(AuditLog::query()->where('action', 'branch.activated')->count())->toBe(1);
});

it('refuses a floor or a room type of another branch', function (): void {
    $other = Branch::factory()->create();
    $floor = Floor::factory()->create(['branch_id' => $other->id]);

    expect(fn () => ($this->save)(null, ($this->room)(['floor_id' => $floor->id])))->toThrow(ModelNotFoundException::class);
    expect(Room::query()->withoutGlobalScopes()->where('number', '150')->exists())->toBeFalse();
});

it('edits a room and logs only what changed', function (): void {
    $room = Hotel::room('101');
    ($this->save)($room->id, ($this->room)(['number' => '101', 'area' => 'Annex', 'is_kiosk_enabled' => false]));

    $log = AuditLog::query()->where('action', 'room.updated')->sole();
    expect($room->fresh()?->area)->toBe('Annex')
        ->and($log->before_json)->toBe(['area' => 'Main', 'is_kiosk_enabled' => true])
        ->and($log->after_json)->toBe(['area' => 'Annex', 'is_kiosk_enabled' => false]);
});

it('puts a free room into maintenance and back through RoomStatusService with reason admin', function (): void {
    $room = Hotel::room('101');
    $data = ($this->room)(['number' => '101', 'room_type_id' => $room->room_type_id, 'floor_id' => $room->floor_id]);

    ($this->save)($room->id, $data, RoomStatus::Maintenance);
    expect($room->fresh()?->status)->toBe(RoomStatus::Maintenance);

    ($this->save)($room->id, $data, RoomStatus::Available);
    expect($room->fresh()?->status)->toBe(RoomStatus::Available);

    $logs = RoomStatusLog::query()->where('room_id', $room->id)->orderBy('id')->get();
    expect($logs)->toHaveCount(2)
        ->and($logs[0]->reason)->toBe(RoomStatusReason::Admin)
        ->and($logs[0]->user_id)->toBe($this->admin->id)
        ->and(AuditLog::query()->where('action', 'room.status_changed')->count())->toBe(2);
});

it('refuses to change the status of an occupied room, or to switch it off or change its type', function (): void {
    Hotel::openShift();
    Hotel::checkIn('205');
    $room = Hotel::room('205');
    $data = ($this->room)(['number' => '205', 'room_type_id' => $room->room_type_id, 'floor_id' => $room->floor_id]);

    expect(fn () => ($this->save)($room->id, $data, RoomStatus::Maintenance))->toThrow(ActionRefused::class, 'Room 205 has a guest in it.');
    expect(fn () => ($this->save)($room->id, $data, RoomStatus::Available))->toThrow(ActionRefused::class, 'Room 205 has a guest in it.');
    expect(fn () => ($this->save)($room->id, [...$data, 'is_active' => false]))->toThrow(ActionRefused::class, 'switch it off');
    expect(fn () => ($this->save)($room->id, [...$data, 'room_type_id' => $this->type->id]))->toThrow(ActionRefused::class, 'change its type');

    expect($room->fresh()?->status)->toBe(RoomStatus::Occupied)
        ->and(AuditLog::query()->where('action', 'like', 'room.%')->count())->toBe(0);
});

it('refuses to change an uncleaned room, and any target other than available or maintenance', function (): void {
    $room = Room::factory()->create(['branch_id' => $this->branchId, 'floor_id' => $this->floor->id, 'room_type_id' => $this->type->id, 'number' => '199', 'status' => RoomStatus::Uncleaned]);
    $data = ($this->room)(['number' => '199']);

    expect(fn () => ($this->save)($room->id, $data, RoomStatus::Available))->toThrow(ActionRefused::class, 'waiting for housekeeping');
    expect(fn () => ($this->save)(Hotel::room('101')->id, ($this->room)(['number' => '101', 'room_type_id' => Hotel::room('101')->room_type_id]), RoomStatus::Occupied))
        ->toThrow(ActionRefused::class, 'only between Available and Maintenance');

    // Back to a state the invariants accept.
    $room->forceFill(['status' => RoomStatus::Available])->save();
});

it('refuses to edit a room of another branch', function (): void {
    $other = Room::factory()->create();

    expect(fn () => ($this->save)($other->id, ($this->room)(['number' => 'X1'])))->toThrow(ModelNotFoundException::class);
    expect($other->fresh()?->number)->not->toBe('X1');
});

it('adds and edits floors and room types, unique within the branch, with audit rows', function (): void {
    $floor = app(SaveFloor::class)->handle($this->admin, $this->branchId, null, 9, '9F');
    app(SaveFloor::class)->handle($this->admin, $this->branchId, $floor->id, 9, 'Roof');
    expect(fn () => app(SaveFloor::class)->handle($this->admin, $this->branchId, null, 1, '1F again'))->toThrow(ActionRefused::class, 'Floor 1 is already');

    $type = app(SaveRoomType::class)->handle($this->admin, $this->branchId, null, 'Family', 'Two queen beds');
    app(SaveRoomType::class)->handle($this->admin, $this->branchId, $type->id, 'Family', 'Two queen beds', false);
    expect(fn () => app(SaveRoomType::class)->handle($this->admin, $this->branchId, null, 'Deluxe', null))->toThrow(ActionRefused::class, 'already a room type called Deluxe');

    expect($floor->fresh()?->label)->toBe('Roof')
        ->and($type->fresh()?->is_active)->toBeFalse()
        ->and(AuditLog::query()->whereIn('action', ['floor.created', 'floor.updated', 'room_type.created', 'room_type.updated'])->count())->toBe(4);
});

// HTTP -------------------------------------------------------------------------------------

it('shows the rooms page (44) with floors and types', function (): void {
    $this->actingAs($this->admin)->get('/admin/rooms?tab=floors')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Rooms')
        ->where('tab', 'floors')
        ->has('rooms', Room::query()->where('branch_id', $this->branchId)->count())
        ->where('rooms.0.number', '101')
        ->where('rooms.0.status_editable', true)
        ->has('floors')
        ->has('types', 3));
});

it('adds and edits a room over HTTP, and shows a refusal inline', function (): void {
    $this->actingAs($this->admin)->from('/admin/rooms')->post('/admin/rooms', ($this->room)())->assertRedirect('/admin/rooms');
    expect(session('inertia.flash_data.toast.message'))->toBe('Room 150 added.');

    $this->from('/admin/rooms')->post('/admin/rooms', ($this->room)())->assertRedirect('/admin/rooms');
    expect(session('inertia.flash_data.error.message'))->toContain('Room 150 is already in this branch.');

    $room = Hotel::room('150');
    $this->from('/admin/rooms')->put("/admin/rooms/{$room->id}", [...($this->room)(), 'status' => 'maintenance'])->assertRedirect('/admin/rooms');
    expect($room->fresh()?->status)->toBe(RoomStatus::Maintenance);
});

it('validates the room form', function (): void {
    $this->actingAs($this->admin)->post('/admin/rooms', ['number' => '1 0 1', 'floor_id' => 'x'])->assertSessionHasErrors(['number', 'floor_id', 'room_type_id', 'is_active']);
});
