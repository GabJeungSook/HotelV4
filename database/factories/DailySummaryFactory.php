<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\DailySummary;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DailySummary> */
class DailySummaryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'business_date' => '2026-11-11',
            'gross_sales_amount' => 1846000,
            'discount_amount' => 30000,
            'net_sales_amount' => 1816000,
            'room_sales_amount' => 1435000,
            'other_sales_amount' => 381000,
            'collected_amount' => 1804500,
            'refund_amount' => 70000,
            'short_amount' => 5000,
            'over_amount' => 0,
            'checkins' => 27,
            'checkouts' => 23,
            'stays_in_house_end' => 23,
            'room_count' => 46,
            'cleans' => 12,
            'late_cleans' => 1,
        ];
    }
}
