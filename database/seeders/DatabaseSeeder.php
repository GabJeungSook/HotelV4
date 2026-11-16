<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * DATABASE_SCHEMA §5. All sample data is made up. DemoShiftSeeder runs only
 * in the local environment.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            OwnerSeeder::class,
            BranchSeeder::class,
            ShiftDefinitionSeeder::class,
            RoomSeeder::class,
            RateSeeder::class,
            ExtensionRateSeeder::class,
            DiscountSeeder::class,
            ListSeeder::class,
            ReasonSeeder::class,
            ProductSeeder::class,
            UserSeeder::class,
        ]);

        if (app()->environment('local')) {
            $this->call(DemoShiftSeeder::class);
            $this->call(SecondBranchSeeder::class);
        }
    }
}
