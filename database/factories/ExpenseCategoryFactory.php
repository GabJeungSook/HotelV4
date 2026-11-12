<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExpenseCategory> */
class ExpenseCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->unique()->words(2, true),
            'needs_note' => false,
            'needs_manager' => false,
            'is_active' => true,
        ];
    }
}
