<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Floor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Floor> */
class FloorFactory extends Factory
{
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 30000);

        return [
            'branch_id' => Branch::factory(),
            'number' => $number,
            'label' => $number.'F',
        ];
    }
}
