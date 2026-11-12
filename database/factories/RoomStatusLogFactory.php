<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Models\Room;
use App\Models\RoomStatusLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RoomStatusLog> */
class RoomStatusLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'from_status' => RoomStatus::Available,
            'to_status' => RoomStatus::Occupied,
            'reason' => RoomStatusReason::CheckIn,
            'created_at' => '2026-11-11 01:00:00',
        ];
    }
}
