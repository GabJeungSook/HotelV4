<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\SummaryDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SummaryDay> */
class SummaryDayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'business_date' => '2026-11-11',
            'dirty_at' => '2026-11-11 01:00:00',
            'rebuilt_at' => null,
        ];
    }
}
