<?php

declare(strict_types=1);

namespace App\Actions\Housekeeping;

use App\Enums\CleaningResult;
use App\Enums\CleaningStatus;
use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Exceptions\ActionRefused;
use App\Models\CleaningTask;
use App\Models\Room;
use App\Models\User;
use App\Services\RoomStatusService;
use App\Support\Clock;
use App\Support\Transaction;

/**
 * "Room is ready" or "Report a problem in this room" (33, DATABASE_SCHEMA §8.11, APP_FLOW §4.17
 * steps 4–6). Locks the room, then the task (in progress, cleaned by this user). Refused while
 * the room has an active stay (edge case 41). Task → done with finished_at, is_late (finished
 * after due_at) and the result; the room goes cleaning → available, or → maintenance with the
 * problem note.
 */
final class FinishCleaning
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RoomStatusService $rooms,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $by, CleaningTask $task, CleaningResult $result = CleaningResult::Ready, ?string $problemNote = null): CleaningTask
    {
        $note = $problemNote === null ? '' : trim($problemNote);

        if ($result === CleaningResult::Problem && $note === '') {
            throw ActionRefused::noteRequired('A problem report');
        }

        return $this->transaction->run(function () use ($by, $task, $result, $note): CleaningTask {
            $room = Room::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($task->room_id);
            $locked = CleaningTask::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($task->id);

            if ($locked->status !== CleaningStatus::InProgress || $locked->cleaned_by !== $by->id) {
                throw ActionRefused::because("You are not cleaning Room {$room->number}.");
            }

            if ($room->activeStay()->exists()) {
                throw ActionRefused::because("Room {$room->number} has a guest in it. Nothing was saved; ask the front desk.");
            }

            $now = $this->clock->now();
            $locked->update([
                'status' => CleaningStatus::Done,
                'finished_at' => $now,
                'is_late' => $now->greaterThan($locked->due_at),
                'result' => $result,
                'problem_note' => $result === CleaningResult::Problem ? mb_substr($note, 0, 255) : null,
            ]);

            $this->rooms->transition(
                $room,
                $result === CleaningResult::Problem ? RoomStatus::Maintenance : RoomStatus::Available,
                RoomStatusReason::CleanFinish,
                by: $by,
            );

            return $locked;
        });
    }
}
