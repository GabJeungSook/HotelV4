<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'actor_id' => null,
            'action' => 'rate.updated',
            'subject_type' => 'rate',
            'subject_id' => 1,
            'before_json' => ['amount' => 45000],
            'after_json' => ['amount' => 50000],
            'created_at' => '2026-11-11 02:00:00',
        ];
    }
}
