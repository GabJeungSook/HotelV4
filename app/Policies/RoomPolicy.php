<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\Room;
use App\Models\User;

final class RoomPolicy
{
    /**
     * The all-time room history (new screen): front desk, supervisor and admin of the room's own
     * branch, and the owner for any branch. A room of another branch never binds for the others
     * (BranchScope), so this is the explicit check on top of that.
     */
    public function viewHistory(User $user, Room $room): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->role === Role::Owner) {
            return true;
        }

        return in_array($user->role, [Role::Frontdesk, Role::Supervisor, Role::Admin], true)
            && $user->branch_id !== null
            && $room->branch_id === $user->branch_id;
    }
}
