<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HallBookingStatus;
use App\Models\Branch;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<HallBooking> */
class HallBookingFactory extends Factory
{
    public function definition(): array
    {
        $start = CarbonImmutable::parse('2026-12-01 01:00:00'); // 9 AM Manila
        $end = $start->addHours(5);

        return [
            'branch_id' => Branch::factory(),
            'hall_id' => fn (array $a) => Hall::factory()->state(['branch_id' => $a['branch_id']]),
            'code' => strtoupper(Str::random(8)),
            'status' => HallBookingStatus::Reserved,
            'customer_name' => fake()->name(),
            'customer_mobile' => '09175550000',
            'event_date' => $start->toDateString(),
            'start_at' => $start,
            'end_at' => $end,
            'pax' => 100,
            'quoted_amount' => 500000,
            'created_by' => fn (array $a) => User::factory()->state(['branch_id' => $a['branch_id']]),
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
