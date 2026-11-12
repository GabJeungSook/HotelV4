<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PosMode;
use App\Models\Branch;
use App\Models\PosOrder;
use App\Models\ShiftSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A walk-in POS order on the branch's open shift by default.
 *
 * @extends Factory<PosOrder>
 */
class PosOrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'shift_session_id' => fn (array $a) => ShiftSessionFactory::openFor($a['branch_id']),
            'stay_id' => null,
            'mode' => PosMode::WalkIn,
            'total_amount' => 2500,
            'created_by' => fn (array $a) => ShiftSession::query()->withoutGlobalScopes()->whereKey($a['shift_session_id'])->value('opened_by'),
            'order_no' => fn () => 'P-'.fake()->unique()->numerify('######'),
            'occurred_at' => '2026-11-11 01:00:00',
            'created_at' => '2026-11-11 01:00:00',
        ];
    }
}
