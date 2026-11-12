<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Enums\Role;
use App\Exceptions\ShiftFull;
use App\Models\Branch;
use App\Models\ShiftMember;
use App\Models\User;
use App\Services\CurrentShift;
use App\Support\Clock;
use App\Support\Transaction;

/**
 * Join the open shift as another front desk (screen 2b, APP_FLOW §4.3). Refused at the
 * branch's max_frontdesks_per_shift; supervisors do not count toward it (TRD §4.9). There is
 * one drawer, so no cash is entered. Joining twice returns the existing membership.
 */
final class JoinShift
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CurrentShift $currentShift,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $by, int $branchId): ShiftMember
    {
        return $this->transaction->run(function () use ($by, $branchId): ShiftMember {
            $shift = $this->currentShift->forWrite($branchId);

            $existing = ShiftMember::query()->where('shift_session_id', $shift->id)->where('user_id', $by->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($by->role === Role::Frontdesk) {
                $max = Branch::query()->findOrFail($branchId)->max_frontdesks_per_shift;
                $frontdesks = ShiftMember::query()
                    ->where('shift_session_id', $shift->id)
                    ->whereHas('user', fn ($q) => $q->where('role', Role::Frontdesk))
                    ->count();

                if ($frontdesks >= $max) {
                    throw ShiftFull::limit($max);
                }
            }

            return ShiftMember::query()->create([
                'shift_session_id' => $shift->id,
                'user_id' => $by->id,
                'joined_at' => $this->clock->now(),
            ]);
        });
    }
}
