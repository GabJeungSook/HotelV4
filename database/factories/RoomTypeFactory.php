<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RoomType> */
class RoomTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->unique()->word().' room',
            'description' => fake()->sentence(6),
            'is_active' => true,
        ];
    }
}
