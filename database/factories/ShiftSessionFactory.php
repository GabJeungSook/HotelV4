<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ShiftStatus;
use App\Models\Branch;
use App\Models\ShiftDefinition;
use App\Models\ShiftSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open AM shift of 11 Nov 2026 by default. A branch can have only one open
 * shift, so use closed() for history, or ShiftSessionFactory::openFor() to reuse it.
 *
 * @extends Factory<ShiftSession>
 */
class ShiftSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'shift_definition_id' => fn (array $a) => ShiftDefinition::factory()->state(['branch_id' => $a['branch_id']]),
            'period_name' => 'AM',
            'business_date' => '2026-11-11',
            'status' => ShiftStatus::Open,
            'opened_at' => '2026-11-11 00:00:00',
            'opened_by' => fn (array $a) => User::factory()->frontdesk()->state(['branch_id' => $a['branch_id']]),
            'opening_cash' => 500000,
        ];
    }

    /** The open (or closing) shift of a branch, created if there is none. */
    public static function openFor(int $branchId): int
    {
        $id = ShiftSession::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereIn('status', [ShiftStatus::Open, ShiftStatus::Closing])
            ->value('id');

        return is_int($id) ? $id : ShiftSession::factory()->create(['branch_id' => $branchId])->id;
    }

    public function closing(): static
    {
        return $this->state(['status' => ShiftStatus::Closing]);
    }

    /** Closed with the opener as closer, no variance and an empty Z chain entry. */
    public function closed(int $zNo = 1): static
    {
        return $this->state(fn (array $a): array => [
            'status' => ShiftStatus::Closed,
            'closed_at' => '2026-11-11 12:00:00',
            'closed_by' => fn (array $x) => $x['opened_by'],
            'expected_cash' => $a['opening_cash'],
            'counted_cash' => $a['opening_cash'],
            'variance_amount' => 0,
            'totals' => [],
            'z_no' => $zNo,
            'grand_total_before' => 0,
            'grand_total_after' => 0,
        ]);
    }
}
