<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CashMovementKind;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\ExpenseCategory;
use App\Models\ShiftSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A ₱150 expense on the branch's open shift by default.
 *
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'shift_session_id' => fn (array $a) => ShiftSessionFactory::openFor($a['branch_id']),
            'kind' => CashMovementKind::Expense,
            'expense_category_id' => fn (array $a) => ExpenseCategory::factory()->state(['branch_id' => $a['branch_id']]),
            'amount' => 15000,
            'note' => 'Ice for the kitchen',
            'created_by' => fn (array $a) => ShiftSession::query()->withoutGlobalScopes()->whereKey($a['shift_session_id'])->value('opened_by'),
            'occurred_at' => '2026-11-11 02:00:00',
            'idempotency_key' => fn () => (string) Str::uuid(),
        ];
    }

    public function remittance(): static
    {
        return $this->state(['kind' => CashMovementKind::Remittance, 'expense_category_id' => null, 'note' => 'Remittance to the office']);
    }
}
