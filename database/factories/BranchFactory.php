<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BranchStatus;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        $city = fake()->unique()->city();

        return [
            'name' => 'Hotel '.$city,
            'address' => fake()->streetAddress().', '.$city,
            'slip_header' => mb_strtoupper('Hotel '.$city),
            'code_prefix' => fake()->unique()->regexify('[A-Z]{3}'),
            'status' => BranchStatus::Active,
            'payment_methods' => ['cash', 'gcash'],
        ];
    }

    public function settingUp(): static
    {
        return $this->state(['status' => BranchStatus::SettingUp]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => BranchStatus::Inactive]);
    }
}
