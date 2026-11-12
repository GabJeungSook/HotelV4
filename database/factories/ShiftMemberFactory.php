<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ShiftMember;
use App\Models\ShiftSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShiftMember> */
class ShiftMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shift_session_id' => ShiftSession::factory(),
            'user_id' => fn (array $a) => User::factory()->frontdesk()->state([
                'branch_id' => ShiftSession::query()->withoutGlobalScopes()->whereKey($a['shift_session_id'])->value('branch_id'),
            ]),
            'joined_at' => '2026-11-11 00:00:00',
        ];
    }
}
