<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CleaningStatus;
use App\Enums\Role;
use App\Models\CleaningTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Housekeeping (32–35, TRD §4.1): a housekeeper of the task's branch. Starting needs the room
 * on one of the user's floors; the cleaning screen and its finish belong to the person
 * cleaning (APP_FLOW §2: "task id, in progress by this user").
 */
final class CleaningTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->role === Role::Housekeeper && $user->branch_id !== null;
    }

    public function start(User $user, CleaningTask $task): bool
    {
        return $this->viewAny($user)
            && $task->branch_id === $user->branch_id
            && DB::table('floor_user')
                ->join('rooms', 'rooms.floor_id', '=', 'floor_user.floor_id')
                ->where('floor_user.user_id', $user->id)
                ->where('rooms.id', $task->room_id)
                ->exists();
    }

    public function finish(User $user, CleaningTask $task): bool
    {
        return $this->viewAny($user)
            && $task->branch_id === $user->branch_id
            && $task->status === CleaningStatus::InProgress
            && $task->cleaned_by === $user->id;
    }
}
