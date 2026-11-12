<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/** Stock (20, TRD §4.1): front desk work on the user's own branch. */
final class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->create($user);
    }

    public function create(User $user): bool
    {
        return $user->is_active && in_array($user->role, [Role::Frontdesk, Role::Supervisor], true) && $user->branch_id !== null;
    }
}
