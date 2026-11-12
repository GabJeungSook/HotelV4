<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReasonKind;
use App\Models\Branch;
use App\Models\Reason;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Reason> */
class ReasonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'kind' => ReasonKind::VoidLine,
            'name' => fake()->unique()->sentence(3),
            'needs_note' => false,
            'needs_manager' => false,
            'is_active' => true,
        ];
    }

    public function kind(ReasonKind $kind): static
    {
        return $this->state(['kind' => $kind]);
    }
}
