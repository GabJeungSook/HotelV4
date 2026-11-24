<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Hall;
use App\Models\HallPackage;
use Illuminate\Database\Seeder;

/**
 * Dev-only: turn on the event-hall module for every branch and seed a couple of halls and packages,
 * so the Function halls screen can be tried without setting it up by hand. Not part of DatabaseSeeder
 * (tests never run it); invoke with `php artisan db:seed --class=HallDemoSeeder`.
 */
class HallDemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Branch::query()->get() as $branch) {
            $branch->update(['event_hall_enabled' => true]);

            foreach ([['Grand Ballroom', 300, 800000], ['Garden Pavilion', 150, 500000]] as [$name, $capacity, $rate]) {
                Hall::query()->updateOrCreate(
                    ['branch_id' => $branch->id, 'name' => $name],
                    ['capacity' => $capacity, 'base_rate_amount' => $rate, 'is_active' => true],
                );
            }

            $packages = [
                ['Venue — half day', 'venue', 'flat', 500000],
                ['Venue — whole day', 'venue', 'flat', 800000],
                ['Buffet set A', 'food', 'per_pax', 35000],
                ['Buffet set B', 'food', 'per_pax', 45000],
                ['Host / emcee', 'host', 'flat', 300000],
                ['Sound system', 'other', 'flat', 250000],
            ];

            foreach ($packages as [$name, $kind, $pricing, $unit]) {
                HallPackage::query()->updateOrCreate(
                    ['branch_id' => $branch->id, 'name' => $name],
                    ['kind' => $kind, 'pricing' => $pricing, 'unit_amount' => $unit, 'is_active' => true],
                );
            }
        }
    }
}
