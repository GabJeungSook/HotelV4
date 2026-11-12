<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CashCount;
use App\Models\ShiftSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashCount> */
class CashCountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shift_session_id' => ShiftSession::factory()->closing(),
            'denominations' => ['100000' => 5],
            'total_cash' => 500000,
            'counted_by' => fn (array $a) => ShiftSession::query()->withoutGlobalScopes()->whereKey($a['shift_session_id'])->value('opened_by') ?? User::factory(),
            'created_at' => '2026-11-11 12:00:00',
        ];
    }
}
