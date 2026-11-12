<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BranchStatus;
use App\Models\Branch;
use Illuminate\Database\Seeder;

/** "Hotel Dalisay GenSan" (made up). Settings not listed keep their schema defaults. */
class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()->create([
            'name' => 'Hotel Dalisay GenSan',
            'address' => 'Gensan Drive, General Santos City',
            'slip_header' => 'HOTEL DALISAY GENSAN',
            'code_prefix' => DemoBranch::PREFIX,
            'status' => BranchStatus::Active,
            'hourly_enabled' => true,
            'overnight_enabled' => true,
            'deposit_enabled' => true,
            'deposit_amount' => 20000,
            'payment_methods' => ['cash', 'gcash'],
            'kiosk_enabled' => true,
            'reservations_enabled' => true,
            'house_rules' => 'Quiet hours 10 PM to 6 AM. No smoking in rooms. Return the key and the remote at check-out.',
        ]);
    }
}
