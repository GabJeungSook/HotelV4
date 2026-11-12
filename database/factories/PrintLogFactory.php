<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PrintDocument;
use App\Enums\PrintFormat;
use App\Models\Branch;
use App\Models\PrintLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PrintLog> */
class PrintLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'document' => PrintDocument::CheckinSlip,
            'subject_id' => 1,
            'format' => PrintFormat::Print,
            'is_reprint' => false,
            'user_id' => fn (array $a) => User::factory()->frontdesk()->state(['branch_id' => $a['branch_id']]),
            'created_at' => '2026-11-11 01:00:00',
        ];
    }
}
