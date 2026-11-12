<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\TransferReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransferReason> */
class TransferReasonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->unique()->sentence(3),
            'needs_note' => false,
            'needs_manager' => false,
            'is_active' => true,
        ];
    }
}
