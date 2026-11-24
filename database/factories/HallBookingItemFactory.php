<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HallPricing;
use App\Models\Branch;
use App\Models\HallBooking;
use App\Models\HallBookingItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HallBookingItem> */
class HallBookingItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'hall_booking_id' => fn (array $a) => HallBooking::factory()->state(['branch_id' => $a['branch_id']]),
            'hall_package_id' => null,
            'label' => 'Venue',
            'pricing' => HallPricing::Flat,
            'quantity' => 1,
            'unit_amount' => 500000,
            'amount' => 500000,
        ];
    }
}
