<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\HourlySalesSummary;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HourlySalesSummary> */
class HourlySalesSummaryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'business_date' => '2026-11-11',
            'local_date' => '2026-11-11',
            'hour' => 9,
            'net_sales_amount' => 150000,
            'checkins' => 3,
        ];
    }
}
