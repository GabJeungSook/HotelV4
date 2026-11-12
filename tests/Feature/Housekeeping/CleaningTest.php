<?php

declare(strict_types=1);

use App\Actions\Housekeeping\FinishCleaning;
use App\Actions\Housekeeping\StartCleaning;
use App\Actions\Stays\CheckOutGuest;
use App\Enums\CleaningResult;
use App\Enums\CleaningStatus;
use App\Enums\CleaningTrigger;
use App\Enums\RoomStatus;
use App\Exceptions\ActionRefused;
use App\Models\CleaningTask;
use App\Models\RoomStatusLog;
use App\Models\Stay;
use App\Models\User;
use App\Queries\CleaningQuery;
use App\Support\Clock;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\Hotel;

/*
| Housekeeping (32–35): StartCleaning, FinishCleaning and CleaningQuery — TRD §4.7, §10
| "Housekeeping", APP_FLOW §4.17, DATABASE_SCHEMA §8.11. Dodong cleans floors 1–2, Joy 2–3.
| Branch settings: deadline 240 min, urgent 120 min, target 15 min.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00'); // 9:00 AM Manila
    Hotel::openShift();
    $this->dodong = Hotel::user('dodong.lumayag');
    $this->joy = Hotel::user('joy.pacana');
    // Check a guest in and out of a room; returns the cleaning task the check-out queued.
    $this->leave = function (string $room): CleaningTask {
        $stay = Hotel::checkIn($room, 6);
        $this->travelClock(1);
        app(CheckOutGuest::class)->handle(Hotel::frontdesk(), $stay);

        return CleaningTask::query()->where('stay_id', $stay->id)->sole();
    };
    $this->online = function (User ...$users): void {
        foreach ($users as $u) {
            $u->forceFill(['last_seen_at' => app(Clock::class)->now()])->save();
        }
    };
    $this->start = fn (User $u, CleaningTask $t) => app(StartCleaning::class)->handle($u, $t);
    $this->finish = fn (User $u, CleaningTask $t, CleaningResult $r = CleaningResult::Ready, ?string $note = null) => app(FinishCleaning::class)->handle($u, $t, $r, $note);
});

it('check-out creates one task with the right deadline', function (): void {
    $task = ($this->leave)('205');

    expect(CleaningTask::query()->count())->toBe(1)
        ->and([$task->status, $task->trigger_kind, $task->room_id])->toBe([CleaningStatus::Queued, CleaningTrigger::CheckOut, Hotel::room('205')->id])
        ->and($task->due_at->toDateTimeString())->toBe($task->queued_at->addMinutes(240)->toDateTimeString())
        ->and(Hotel::room('205')->status)->toBe(RoomStatus::Uncleaned);
});

it('only the first N are startable, N = housekeepers of those floors online', function (): void {
    $first = ($this->leave)('205');
    $second = ($this->leave)('206');
    $third = ($this->leave)('101');

    // Nobody online yet: at least one is startable.
    expect(app(CleaningQuery::class)->startableIds($this->dodong))->toBe([$first->id]);

    // Dodong and Joy (who shares floor 2) online: two startable for Dodong.
    ($this->online)($this->dodong, $this->joy);
    expect(app(CleaningQuery::class)->startableIds($this->dodong))->toBe([$first->id, $second->id]);

    $queue = app(CleaningQuery::class)->queue($this->dodong);
    expect(array_column($queue['tasks'], 'startable'))->toBe([true, true, false])
        ->and(array_column($queue['tasks'], 'next_in_line'))->toBe([false, false, true])
        ->and($queue['online'])->toBe(2)
        ->and($queue['floors'])->toBe('Floors 1–2');

    expect(fn () => ($this->start)($this->dodong, $third))->toThrow(ActionRefused::class, 'not next in line');
    expect($third->fresh()->status)->toBe(CleaningStatus::Queued);
});

it('starts a task: in progress, started_at, cleaned_by, room uncleaned → cleaning', function (): void {
    $task = ($this->leave)('205');

    ($this->start)($this->dodong, $task);

    $task->refresh();
    expect([$task->status, $task->cleaned_by, $task->started_at?->toDateTimeString()])->toBe([CleaningStatus::InProgress, $this->dodong->id, '2026-11-12 01:01:00'])
        ->and(Hotel::room('205')->status)->toBe(RoomStatus::Cleaning)
        ->and(RoomStatusLog::query()->latest('id')->first()?->user_id)->toBe($this->dodong->id);
});

it('second start by same user refused', function (): void {
    ($this->online)($this->dodong, $this->joy);
    $first = ($this->leave)('205');
    $second = ($this->leave)('206');
    ($this->start)($this->dodong, $first);

    expect(fn () => ($this->start)($this->dodong, $second))->toThrow(ActionRefused::class, 'Finish the room you are cleaning first');
    expect($second->fresh()->status)->toBe(CleaningStatus::Queued)
        ->and(Hotel::room('206')->status)->toBe(RoomStatus::Uncleaned);

    // While Dodong is busy, his own task is the only one for him; Joy can still start the next.
    expect(app(CleaningQuery::class)->queue($this->dodong)['current']['id'])->toBe($first->id);
    ($this->start)($this->joy, $second);
    expect($second->fresh()->cleaned_by)->toBe($this->joy->id);
});

it('finish sets room available', function (): void {
    $task = ($this->leave)('205');
    ($this->start)($this->dodong, $task);
    $this->travelClock(14);

    ($this->finish)($this->dodong, $task);

    $task->refresh();
    expect([$task->status, $task->result, $task->is_late, $task->finished_at?->toDateTimeString()])->toBe([CleaningStatus::Done, CleaningResult::Ready, false, '2026-11-12 01:15:00'])
        ->and(Hotel::room('205')->status)->toBe(RoomStatus::Available);
});

it('finish refused when room has an active stay', function (): void {
    $task = ($this->leave)('205');
    ($this->start)($this->dodong, $task);
    Stay::factory()->held()->create(['branch_id' => Hotel::branch()->id, 'room_id' => Hotel::room('205')->id, 'hold_expires_at' => '2026-11-12 02:00:00']);

    expect(fn () => ($this->finish)($this->dodong, $task))->toThrow(ActionRefused::class, 'has a guest in it');
    expect($task->fresh()->status)->toBe(CleaningStatus::InProgress)
        ->and(Hotel::room('205')->status)->toBe(RoomStatus::Cleaning);
});

it('refuses to finish a task someone else is cleaning', function (): void {
    $task = ($this->leave)('205');
    ($this->start)($this->dodong, $task);

    expect(fn () => ($this->finish)($this->joy, $task))->toThrow(ActionRefused::class, 'not cleaning Room 205');
});

it('late flag', function (): void {
    $task = ($this->leave)('205');
    $this->travelClock(239);
    ($this->start)($this->dodong, $task);
    $this->travelClock(5); // 244 minutes after check-out, past the 240-minute deadline

    ($this->finish)($this->dodong, $task);

    expect($task->fresh()->is_late)->toBeTrue();

    $history = app(CleaningQuery::class)->history($this->dodong);
    expect([$history['count'], $history['late'], $history['average_minutes']])->toBe([1, 1, 5])
        ->and($history['late_note'])->toBe('Room 205 was finished 4 minutes after its 4-hour deadline.');
});

it('problem → maintenance', function (): void {
    $task = ($this->leave)('205');
    ($this->start)($this->dodong, $task);

    expect(fn () => ($this->finish)($this->dodong, $task, CleaningResult::Problem, ' '))->toThrow(ActionRefused::class);

    ($this->finish)($this->dodong, $task, CleaningResult::Problem, 'Aircon leaking');

    $task->refresh();
    expect([$task->status, $task->result, $task->problem_note])->toBe([CleaningStatus::Done, CleaningResult::Problem, 'Aircon leaking'])
        ->and(Hotel::room('205')->status)->toBe(RoomStatus::Maintenance);
});

it('marks a room urgent after cleaning_urgent_minutes of waiting', function (): void {
    ($this->leave)('205');
    $this->travelClock(130);

    $row = app(CleaningQuery::class)->queue($this->dodong)['tasks'][0];

    expect([$row['urgent'], $row['tone'], $row['waiting_minutes']])->toBe([true, 'bad', 130]);
});
