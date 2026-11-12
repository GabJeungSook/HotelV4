<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Stay;
use App\Models\StayRoom;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StayRoom> */
class StayRoomFactory extends Factory
{
    public function definition(): array
    {
        return [
            'stay_id' => Stay::factory(),
            'room_id' => fn (array $a) => Stay::query()->withoutGlobalScopes()->whereKey($a['stay_id'])->value('room_id'),
            'from_at' => '2026-11-11 01:00:00',
            'created_at' => '2026-11-11 01:00:00',
        ];
    }
}
