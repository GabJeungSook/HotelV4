<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ShiftDefinition;
use Illuminate\Database\Seeder;

/** AM 08:00–20:00, PM 20:00–08:00 (branch local time). */
class ShiftDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $branchId = DemoBranch::get()->id;

        foreach ([['AM', '08:00:00', '20:00:00', 1], ['PM', '20:00:00', '08:00:00', 2]] as [$name, $starts, $ends, $order]) {
            ShiftDefinition::query()->create([
                'branch_id' => $branchId,
                'name' => $name,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'sort_order' => $order,
            ]);
        }
    }
}
