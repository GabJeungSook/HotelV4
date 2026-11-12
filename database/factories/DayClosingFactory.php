<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\DayClosing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DayClosing> */
class DayClosingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'business_date' => '2026-11-10',
            'day_z_no' => 1,
            'net_sales_amount' => 0,
            'short_amount' => 0,
            'over_amount' => 0,
            'grand_total_before' => 0,
            'grand_total_after' => 0,
            'shift_z_from' => 1,
            'shift_z_to' => 2,
            'closed_by' => fn (array $a) => User::factory()->backoffice()->state(['branch_id' => $a['branch_id']]),
            'created_at' => '2026-11-11 01:00:00',
        ];
    }
}
