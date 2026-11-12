<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CashCountDraft;
use App\Models\ShiftSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashCountDraft> */
class CashCountDraftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shift_session_id' => ShiftSession::factory()->closing(),
            'denominations' => ['100000' => 3, '50000' => 2],
            'updated_by' => fn (array $a) => ShiftSession::query()->withoutGlobalScopes()->whereKey($a['shift_session_id'])->value('opened_by'),
        ];
    }
}
