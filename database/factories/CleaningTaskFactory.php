<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CleaningStatus;
use App\Enums\CleaningTrigger;
use App\Models\CleaningTask;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A queued clean after a check-out by default.
 *
 * @extends Factory<CleaningTask>
 */
class CleaningTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'stay_id' => Stay::factory()->checkedOut(),
            'branch_id' => fn (array $a) => Stay::query()->withoutGlobalScopes()->whereKey($a['stay_id'])->value('branch_id'),
            'room_id' => fn (array $a) => Stay::query()->withoutGlobalScopes()->whereKey($a['stay_id'])->value('room_id'),
            'trigger_kind' => CleaningTrigger::CheckOut,
            'status' => CleaningStatus::Queued,
            'queued_at' => '2026-11-11 11:00:00',
            'due_at' => '2026-11-11 15:00:00',
        ];
    }

    public function inProgress(?int $cleanerId = null): static
    {
        return $this->state(fn (array $a): array => [
            'status' => CleaningStatus::InProgress,
            'started_at' => '2026-11-11 11:30:00',
            'cleaned_by' => $cleanerId ?? fn (array $x) => User::factory()->housekeeper()->state(['branch_id' => $x['branch_id']]),
        ]);
    }
}
