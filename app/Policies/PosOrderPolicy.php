<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\PosOrder;
use App\Models\User;

/**
 * POS (19, TRD §4.1): front desk work on the user's own branch. The `role`, `shift.member` and
 * `module:pos_enabled` middleware guard the routes; this guards the record.
 */
final class PosOrderPolicy
{
    public function create(User $user): bool
    {
        return $user->is_active && in_array($user->role, [Role::Frontdesk, Role::Supervisor], true) && $user->branch_id !== null;
    }

    public function void(User $user, PosOrder $order): bool
    {
        return $this->create($user) && $order->branch_id === $user->branch_id;
    }
}
