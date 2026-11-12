<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ShiftForwardedStay;
use App\Models\Stay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShiftForwardedStay> */
class ShiftForwardedStayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'stay_id' => Stay::factory(),
            'shift_session_id' => fn (array $a) => $this->stay($a)->checkin_shift_id,
            'room_id' => fn (array $a) => $this->stay($a)->room_id,
            'guest_name' => fn (array $a) => $this->stay($a)->guest_name,
            'expected_checkout_at' => fn (array $a) => $this->stay($a)->expected_checkout_at,
            'unpaid_amount' => 0,
            'key_deposit_held' => 20000,
            'guest_deposit_held' => 0,
            'created_at' => '2026-11-11 12:00:00',
        ];
    }

    /** @param  array<string, mixed>  $attributes */
    private function stay(array $attributes): Stay
    {
        return Stay::query()->withoutGlobalScopes()->findOrFail($attributes['stay_id']);
    }
}
