<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\DiscountKind;
use App\Enums\StayKind;
use App\Models\Discount;
use App\Models\Rate;
use Illuminate\Database\Seeder;

/**
 * Promo ₱50 fixed (6 h rates only, kiosk on) · Senior citizen 20% · PWD 20%.
 * Senior citizen and PWD are VAT-exempt (DATABASE_SCHEMA §4.16, TRD §4.10).
 */
class DiscountSeeder extends Seeder
{
    public function run(): void
    {
        $branchId = DemoBranch::get()->id;

        $promo = Discount::query()->create([
            'branch_id' => $branchId, 'name' => 'Promo', 'kind' => DiscountKind::Fixed, 'value' => 5000,
            'all_rates' => false, 'kiosk_enabled' => true,
        ]);
        $promo->rates()->attach(
            Rate::query()->where('branch_id', $branchId)->where('stay_kind', StayKind::Hourly)->where('hours', 6)->pluck('id'),
        );

        // Senior citizen and PWD: guest-ticked at the kiosk, verified by ID at the desk.
        foreach (['Senior citizen', 'PWD'] as $name) {
            Discount::query()->create([
                'branch_id' => $branchId, 'name' => $name, 'kind' => DiscountKind::Percent, 'value' => 20,
                'all_rates' => true, 'kiosk_enabled' => true, 'needs_id' => true, 'is_vat_exempt' => true,
            ]);
        }
    }
}
