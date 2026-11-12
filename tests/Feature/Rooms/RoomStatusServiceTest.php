<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Exceptions\RoomTransitionNotAllowed;
use App\Models\RoomStatusLog;
use App\Services\RoomStatusService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Hotel;

/*
| RoomStatusService is the only writer of rooms.status (APP_FLOW §5.2).
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00');
    $this->service = app(RoomStatusService::class);
});

it('allows exactly the moves of APP_FLOW §5.2', function (RoomStatus $from, RoomStatus $to, RoomStatusReason $reason): void {
    expect($this->service->allows($from, $to, $reason))->toBeTrue();
})->with([
    'check-in' => [RoomStatus::Available, RoomStatus::Occupied, RoomStatusReason::CheckIn],
    'check-out' => [RoomStatus::Occupied, RoomStatus::Uncleaned, RoomStatusReason::CheckOut],
    'transfer out to uncleaned' => [RoomStatus::Occupied, RoomStatus::Uncleaned, RoomStatusReason::TransferOut],
    'transfer out to maintenance' => [RoomStatus::Occupied, RoomStatus::Maintenance, RoomStatusReason::TransferOut],
    'cancel' => [RoomStatus::Occupied, RoomStatus::Available, RoomStatusReason::Cancel],
    'clean start' => [RoomStatus::Uncleaned, RoomStatus::Cleaning, RoomStatusReason::CleanStart],
    'clean finish ready' => [RoomStatus::Cleaning, RoomStatus::Available, RoomStatusReason::CleanFinish],
    'clean finish problem' => [RoomStatus::Cleaning, RoomStatus::Maintenance, RoomStatusReason::CleanFinish],
    'reset task' => [RoomStatus::Cleaning, RoomStatus::Uncleaned, RoomStatusReason::Admin],
    'admin to maintenance' => [RoomStatus::Available, RoomStatus::Maintenance, RoomStatusReason::Admin],
    'admin back to available' => [RoomStatus::Maintenance, RoomStatus::Available, RoomStatusReason::Admin],
]);

it('refuses every other move', function (RoomStatus $from, RoomStatus $to, RoomStatusReason $reason): void {
    expect($this->service->allows($from, $to, $reason))->toBeFalse();
})->with([
    'uncleaned straight to available' => [RoomStatus::Uncleaned, RoomStatus::Available, RoomStatusReason::Admin],
    'admin empties an occupied room' => [RoomStatus::Occupied, RoomStatus::Available, RoomStatusReason::Admin],
    'admin fills a room' => [RoomStatus::Available, RoomStatus::Occupied, RoomStatusReason::Admin],
    'check-in to a room being cleaned' => [RoomStatus::Cleaning, RoomStatus::Occupied, RoomStatusReason::CheckIn],
    'check-out with housekeeping on' => [RoomStatus::Occupied, RoomStatus::Available, RoomStatusReason::CheckOut],
    'maintenance to occupied' => [RoomStatus::Maintenance, RoomStatus::Occupied, RoomStatusReason::CheckIn],
]);

it('sends a vacated room straight to available when housekeeping is off', function (): void {
    expect($this->service->allows(RoomStatus::Occupied, RoomStatus::Available, RoomStatusReason::CheckOut, housekeepingEnabled: false))->toBeTrue()
        ->and($this->service->allows(RoomStatus::Occupied, RoomStatus::Available, RoomStatusReason::TransferOut, housekeepingEnabled: false))->toBeTrue();
});

it('writes the status and a log row with who and why', function (): void {
    $room = Hotel::room('108');
    $by = Hotel::user('ramil.ortega');

    DB::transaction(fn () => $this->service->transition($room, RoomStatus::Maintenance, RoomStatusReason::Admin, by: $by));

    $log = RoomStatusLog::query()->where('room_id', $room->id)->sole();
    expect($room->fresh()->status)->toBe(RoomStatus::Maintenance)
        ->and($room->status)->toBe(RoomStatus::Maintenance)
        ->and([$log->from_status, $log->to_status, $log->reason, $log->user_id])->toBe([RoomStatus::Available, RoomStatus::Maintenance, RoomStatusReason::Admin, $by->id])
        ->and($log->created_at->toDateTimeString())->toBe('2026-11-12 01:00:00');
});

it('refuses a move that is not allowed and writes nothing', function (): void {
    $room = Hotel::room('108');

    expect(fn () => DB::transaction(fn () => $this->service->transition($room, RoomStatus::Cleaning, RoomStatusReason::CleanStart)))
        ->toThrow(RoomTransitionNotAllowed::class, 'Room 108 cannot go from available to cleaning');
    expect($room->fresh()->status)->toBe(RoomStatus::Available)->and(RoomStatusLog::query()->count())->toBe(0);
});
