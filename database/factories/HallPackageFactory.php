<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HallPackageKind;
use App\Enums\HallPricing;
use App\Models\Branch;
use App\Models\HallPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HallPackage> */
class HallPackageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->unique()->words(2, true).' package',
            'kind' => HallPackageKind::Food,
            'pricing' => HallPricing::PerPax,
            'unit_amount' => 35000,
            'is_active' => true,
        ];
    }
}
