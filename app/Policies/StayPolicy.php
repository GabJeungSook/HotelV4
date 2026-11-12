<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\Stay;
use App\Models\User;

/**
 * Front desk work on a stay (TRD §4.1): a front desk user or a supervisor of the stay's own
 * branch. The `role` and `shift.member` middleware guard the route group; this guards the record.
 */
final class StayPolicy
{
    public function create(User $user): bool
    {
        return $this->frontDesk($user);
    }

    public function view(User $user, Stay $stay): bool
    {
        return $this->frontDesk($user) && $stay->branch_id === $user->branch_id;
    }

    public function update(User $user, Stay $stay): bool
    {
        return $this->view($user, $stay);
    }

    private function frontDesk(User $user): bool
    {
        return $user->is_active && in_array($user->role, [Role::Frontdesk, Role::Supervisor], true) && $user->branch_id !== null;
    }
}
