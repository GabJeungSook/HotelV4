<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\StayKind;
use App\Models\Rate;
use App\Models\RoomType;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * Hourly 6/12/18/24 h and overnight, in pesos. Suite 18 h is deliberately
 * missing so screen 52 (missing rate) can be seen.
 */
class RateSeeder extends Seeder
{
    /** @var array<string, array{hourly: array<int, int>, overnight: int}> */
    public const RATES = [
        'Standard' => ['hourly' => [6 => 350, 12 => 500, 18 => 650, 24 => 800], 'overnight' => 750],
        'Deluxe' => ['hourly' => [6 => 450, 12 => 650, 18 => 850, 24 => 1000], 'overnight' => 950],
        'Suite' => ['hourly' => [6 => 600, 12 => 900, 24 => 1400], 'overnight' => 1300],
    ];

    public function run(): void
    {
        $branchId = DemoBranch::get()->id;

        foreach (self::RATES as $typeName => $rates) {
            $typeId = RoomType::query()->where('branch_id', $branchId)->where('name', $typeName)->value('id');

            foreach ($rates['hourly'] as $hours => $pesos) {
                Rate::query()->create([
                    'branch_id' => $branchId, 'room_type_id' => $typeId,
                    'stay_kind' => StayKind::Hourly, 'hours' => $hours, 'amount' => Money::ofPesos($pesos),
                ]);
            }

            Rate::query()->create([
                'branch_id' => $branchId, 'room_type_id' => $typeId,
                'stay_kind' => StayKind::Overnight, 'hours' => 0, 'amount' => Money::ofPesos($rates['overnight']),
            ]);
        }
    }
}
