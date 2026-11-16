<?php

declare(strict_types=1);

namespace App\Actions\Housekeeping;

use App\Enums\CleaningStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Exceptions\ActionRefused;
use App\Models\AuditLog;
use App\Models\CleaningTask;
use App\Models\Room;
use App\Models\User;
use App\Services\RoomStatusService;
use App\Support\Clock;
use App\Support\Transaction;

/**
 * Reset a stuck cleaning from Needs attention (49, TRD §4.9): the task in progress becomes
 * `reset`, a new `queued` task is made for the same room and stay with the same queued_at and
 * due_at, and the room goes cleaning → uncleaned. Nothing is fixed silently: an audit row
 * records who reset it.
 */
final class ResetCleaning
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RoomStatusService $rooms,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $by, CleaningTask $task): CleaningTask
    {
        return $this->transaction->run(function () use ($by, $task): CleaningTask {
            $room = Room::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($task->room_id);
            $locked = CleaningTask::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($task->id);

            if ($locked->status !== CleaningStatus::InProgress) {
                throw ActionRefused::because("The cleaning of Room {$room->number} is no longer in progress. Nothing was changed.");
            }

            $locked->update(['status' => CleaningStatus::Reset]);

            $fresh = CleaningTask::query()->create([
                'branch_id' => $locked->branch_id,
                'room_id' => $locked->room_id,
                'stay_id' => $locked->stay_id,
                'trigger_kind' => $locked->trigger_kind,
                'status' => CleaningStatus::Queued,
                'queued_at' => $locked->queued_at,
                'due_at' => $locked->due_at,
            ]);

            $this->rooms->transition($room, RoomStatus::Uncleaned, RoomStatusReason::Admin, by: $by);

            AuditLog::query()->create([
                'branch_id' => $locked->branch_id,
                'actor_id' => $by->id,
                'action' => 'cleaning.reset',
                'subject_type' => 'cleaning_task',
                'subject_id' => $locked->id,
                'before_json' => ['status' => CleaningStatus::InProgress->value, 'cleaned_by' => $locked->cleaned_by, 'started_at' => $locked->started_at?->toIso8601String()],
                'after_json' => ['status' => CleaningStatus::Reset->value, 'new_task_id' => $fresh->id, 'room' => $room->number],
                'created_at' => $this->clock->now(),
            ]);

            return $fresh;
        });
    }
}
