<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Hall;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Hall> */
class HallFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->unique()->words(2, true).' Hall',
            'capacity' => 100,
            'base_rate_amount' => 500000,
            'is_active' => true,
        ];
    }
}
