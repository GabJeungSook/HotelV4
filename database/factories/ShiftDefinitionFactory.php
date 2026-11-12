<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ShiftDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShiftDefinition> */
class ShiftDefinitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => 'AM '.fake()->unique()->numberBetween(1, 99999),
            'starts_at' => '08:00:00',
            'ends_at' => '20:00:00',
            'sort_order' => 1,
            'is_active' => true,
        ];
    }

    public function pm(): static
    {
        return $this->state(['name' => 'PM', 'starts_at' => '20:00:00', 'ends_at' => '08:00:00', 'sort_order' => 2]);
    }
}
