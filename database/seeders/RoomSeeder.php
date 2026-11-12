<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Seeder;

/**
 * 3 floors and 46 rooms: Standard 24 (1F), Deluxe 18 (2F), Suite 4 (3F, incl. 3A).
 * Numbers follow the mockup board: no 113 or 213, 221 on 2F, 301 and 3A on 3F.
 */
class RoomSeeder extends Seeder
{
    /** @var array<int, array{label: string, type: string, description: string, numbers: list<string>}> */
    private const FLOORS = [
        1 => ['label' => '1F', 'type' => 'Standard', 'description' => 'Queen bed, aircon, hot shower, TV',
            'numbers' => ['101', '102', '103', '104', '105', '106', '107', '108', '109', '110', '111', '112',
                '114', '115', '116', '117', '118', '119', '120', '121', '122', '123', '124', '125']],
        2 => ['label' => '2F', 'type' => 'Deluxe', 'description' => 'Larger room, queen bed, sofa, smart TV',
            'numbers' => ['201', '202', '203', '204', '205', '206', '207', '208', '209', '210', '211', '212',
                '214', '215', '216', '219', '220', '221']],
        3 => ['label' => '3F', 'type' => 'Suite', 'description' => 'Separate living area, king bed, bathtub',
            'numbers' => ['301', '302', '3A', '3B']],
    ];

    public function run(): void
    {
        $branchId = DemoBranch::get()->id;

        foreach (self::FLOORS as $number => $floor) {
            $floorRow = Floor::query()->create(['branch_id' => $branchId, 'number' => $number, 'label' => $floor['label']]);
            $type = RoomType::query()->create(['branch_id' => $branchId, 'name' => $floor['type'], 'description' => $floor['description']]);

            foreach ($floor['numbers'] as $roomNumber) {
                Room::query()->create([
                    'branch_id' => $branchId,
                    'floor_id' => $floorRow->id,
                    'room_type_id' => $type->id,
                    'number' => $roomNumber,
                ]);
            }
        }
    }
}
