<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StockMovementKind;
use App\Models\Branch;
use App\Models\Product;
use App\Models\ShiftSession;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Stock received on the branch's open shift by default.
 *
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'product_id' => fn (array $a) => Product::factory()->state(['branch_id' => $a['branch_id']]),
            'shift_session_id' => fn (array $a) => ShiftSessionFactory::openFor($a['branch_id']),
            'kind' => StockMovementKind::Receive,
            'quantity' => 24,
            'balance_after' => 74,
            'created_by' => fn (array $a) => ShiftSession::query()->withoutGlobalScopes()->whereKey($a['shift_session_id'])->value('opened_by'),
            'occurred_at' => '2026-11-11 01:00:00',
            'created_at' => '2026-11-11 01:00:00',
        ];
    }
}
