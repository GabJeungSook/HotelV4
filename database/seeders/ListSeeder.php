<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use App\Models\TransferReason;
use Illuminate\Database\Seeder;

/** 6 transfer reasons (screen 13) and 8 expense categories (screen 21). */
class ListSeeder extends Seeder
{
    public function run(): void
    {
        $branchId = DemoBranch::get()->id;

        foreach ([
            ['Aircon not cooling', false, false],
            ['No hot water', false, false],
            ['Noise from next room', false, false],
            ['Guest asked for an upgrade', false, false],
            ['Plumbing or leak', false, false],
            ['Other', true, true],
        ] as [$name, $note, $manager]) {
            TransferReason::query()->create(['branch_id' => $branchId, 'name' => $name, 'needs_note' => $note, 'needs_manager' => $manager]);
        }

        foreach ([
            ['Drinking water', false, false],
            ['Transport and errands', false, false],
            ['Repairs and supplies', false, false],
            ['Cleaning supplies', false, false],
            ['Laundry', false, false],
            ['Food for staff', false, true],
            ['Utilities', false, false],
            ['Other', true, true],
        ] as [$name, $note, $manager]) {
            ExpenseCategory::query()->create(['branch_id' => $branchId, 'name' => $name, 'needs_note' => $note, 'needs_manager' => $manager]);
        }
    }
}
