<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportSection;
use App\Models\Branch;
use App\Models\DailyReportLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DailyReportLine> */
class DailyReportLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'business_date' => '2026-11-11',
            'section' => ReportSection::RoomType,
            'label' => fake()->unique()->word(),
            'quantity' => 5,
            'amount' => 250000,
        ];
    }
}
