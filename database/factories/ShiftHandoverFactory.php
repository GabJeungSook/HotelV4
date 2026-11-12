<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ShiftHandover;
use App\Models\ShiftSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A close without handover by default (no receiving shift).
 *
 * @extends Factory<ShiftHandover>
 */
class ShiftHandoverFactory extends Factory
{
    public function definition(): array
    {
        return [
            'from_shift_id' => ShiftSession::factory()->closed(),
            'to_shift_id' => null,
            'received_by' => null,
            'received_cash' => null,
            'difference_amount' => 0,
            'created_at' => '2026-11-11 12:00:00',
        ];
    }
}
