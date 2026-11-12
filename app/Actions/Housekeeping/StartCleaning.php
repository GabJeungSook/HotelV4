<?php

declare(strict_types=1);

namespace App\Actions\Housekeeping;

use App\Enums\CleaningStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Exceptions\ActionRefused;
use App\Models\CleaningTask;
use App\Models\Room;
use App\Models\User;
use App\Queries\CleaningQuery;
use App\Services\RoomStatusService;
use App\Support\Clock;
use App\Support\DuplicateKey;
use App\Support\Transaction;
use Illuminate\Database\QueryException;

/**
 * "Start cleaning" on the queue (32, APP_FLOW §4.17 step 2–3, TRD §4.7). Locks the room, then
 * the task. Refused when the task is not among the first N startable for this housekeeper, or
 * when they already have a task in progress (cleaning_tasks_one_per_cleaner). Writes
 * status in_progress, started_at, cleaned_by; the room goes uncleaned → cleaning.
 */
final class StartCleaning
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CleaningQuery $cleaning,
        private readonly RoomStatusService $rooms,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $by, CleaningTask $task): CleaningTask
    {
        try {
            return $this->transaction->run(function () use ($by, $task): CleaningTask {
                $room = Room::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($task->room_id);
                $locked = CleaningTask::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($task->id);

                if ($locked->status !== CleaningStatus::Queued) {
                    throw ActionRefused::because("Room {$room->number} is no longer waiting to be cleaned.");
                }

                if ($this->cleaning->inProgress($by) !== null) {
                    throw self::finishFirst();
                }

                if (! in_array($locked->id, $this->cleaning->startableIds($by), true)) {
                    throw ActionRefused::because("Room {$room->number} is not next in line yet.");
                }

                $locked->update([
                    'status' => CleaningStatus::InProgress,
                    'started_at' => $this->clock->now(),
                    'cleaned_by' => $by->id,
                ]);

                $this->rooms->transition($room, RoomStatus::Cleaning, RoomStatusReason::CleanStart, by: $by);

                return $locked;
            });
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'cleaning_tasks_one_per_cleaner')) {
                throw self::finishFirst();
            }

            throw $e;
        }
    }

    private static function finishFirst(): ActionRefused
    {
        return ActionRefused::because('Finish the room you are cleaning first.');
    }
}
