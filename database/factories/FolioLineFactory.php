<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LineKind;
use App\Models\FolioLine;
use App\Models\Stay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The ₱500 room line of a checked-in stay by default.
 *
 * @extends Factory<FolioLine>
 */
class FolioLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'stay_id' => Stay::factory(),
            'branch_id' => fn (array $a) => $this->stay($a)->branch_id,
            'shift_session_id' => fn (array $a) => $this->stay($a)->checkin_shift_id ?? ShiftSessionFactory::openFor($a['branch_id']),
            'room_id' => fn (array $a) => $this->stay($a)->room_id,
            'kind' => LineKind::Room,
            'description' => 'Room 12 hrs',
            'quantity' => 1,
            'unit_amount' => 50000,
            'amount' => fn (array $a) => $a['quantity'] * $a['unit_amount'],
            'created_by' => fn (array $a) => $this->stay($a)->checked_in_by,
            'occurred_at' => '2026-11-11 01:00:00',
            'created_at' => '2026-11-11 01:00:00',
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private function stay(array $attributes): Stay
    {
        return Stay::query()->withoutGlobalScopes()->findOrFail($attributes['stay_id']);
    }
}
