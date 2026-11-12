<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StayKind;
use App\Models\Branch;
use App\Models\Rate;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A 12-hour rate of ₱500 by default.
 *
 * @extends Factory<Rate>
 */
class RateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'room_type_id' => fn (array $a) => RoomType::factory()->state(['branch_id' => $a['branch_id']]),
            'stay_kind' => StayKind::Hourly,
            'hours' => 12,
            'amount' => 50000,
        ];
    }

    public function hourly(int $hours, int $amount): static
    {
        return $this->state(['stay_kind' => StayKind::Hourly, 'hours' => $hours, 'amount' => $amount]);
    }

    public function overnight(int $amount): static
    {
        return $this->state(['stay_kind' => StayKind::Overnight, 'hours' => 0, 'amount' => $amount]);
    }
}
