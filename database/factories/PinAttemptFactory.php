<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PinContext;
use App\Models\PinAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A wrong PIN on switch-user by default.
 *
 * @extends Factory<PinAttempt>
 */
class PinAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_key' => (string) Str::uuid(),
            'context' => PinContext::SwitchUser,
            'succeeded' => false,
            'created_at' => '2026-11-11 01:00:00',
        ];
    }
}
