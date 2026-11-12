<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ExtensionRate;
use App\Support\Money;
use Illuminate\Database\Seeder;

/** 3 h ₱150 · 6 h ₱250 · 12 h ₱400 · 24 h ₱700 */
class ExtensionRateSeeder extends Seeder
{
    public function run(): void
    {
        $branchId = DemoBranch::get()->id;

        foreach ([3 => 150, 6 => 250, 12 => 400, 24 => 700] as $hours => $pesos) {
            ExtensionRate::query()->create(['branch_id' => $branchId, 'hours' => $hours, 'amount' => Money::ofPesos($pesos)]);
        }
    }
}
