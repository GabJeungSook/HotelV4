<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ApprovalAction;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Approval> */
class ApprovalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'action' => ApprovalAction::VoidLine,
            'subject_type' => 'folio_line',
            'subject_id' => 1,
            'requested_by' => fn (array $a) => User::factory()->frontdesk()->state(['branch_id' => $a['branch_id']]),
            'approved_by' => fn (array $a) => User::factory()->supervisor()->state(['branch_id' => $a['branch_id']]),
            'reason' => 'Typed wrong amount',
            'summary' => 'Void Cup noodles · −₱45.00',
            'created_at' => '2026-11-11 02:00:00',
        ];
    }
}
