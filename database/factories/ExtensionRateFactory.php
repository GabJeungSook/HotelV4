<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ExtensionRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExtensionRate> */
class ExtensionRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'hours' => 6,
            'amount' => 25000,
        ];
    }
}
