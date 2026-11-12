<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RoomStatus;
use App\Models\Branch;
use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An available room; its floor and type belong to the same branch.
 *
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'floor_id' => fn (array $a) => Floor::factory()->state(['branch_id' => $a['branch_id']]),
            'room_type_id' => fn (array $a) => RoomType::factory()->state(['branch_id' => $a['branch_id']]),
            'number' => (string) fake()->unique()->numberBetween(100, 99999),
            'area' => 'Main',
            'status' => RoomStatus::Available,
            'is_kiosk_enabled' => true,
            'is_active' => true,
        ];
    }

    public function status(RoomStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function occupied(): static
    {
        return $this->status(RoomStatus::Occupied);
    }

    public function uncleaned(): static
    {
        return $this->status(RoomStatus::Uncleaned);
    }
}
