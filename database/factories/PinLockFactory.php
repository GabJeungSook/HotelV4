<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PinLock;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * An open (still locked) PIN lock by default.
 *
 * @extends Factory<PinLock>
 */
class PinLockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_key' => (string) Str::uuid(),
            'locked_at' => '2026-11-11 01:00:00',
        ];
    }

    public function unlocked(): static
    {
        return $this->state([
            'unlocked_at' => '2026-11-11 01:05:00',
            'unlocked_by' => User::factory()->supervisor(),
        ]);
    }
}
