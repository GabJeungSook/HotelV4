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

// Two roomboys on the same floor both see Room 205 at the top. They cannot both clean it: the row
// lock in StartCleaning serialises them and the status check rejects the loser.
it('does not let two roomboys clean the same room — the first wins', function (): void {
    $task = ($this->leave)('205'); // floor 2, both are assigned to it
    ($this->online)($this->dodong, $this->joy); // heartbeat after the check-out advanced the clock

    ($this->start)($this->dodong, $task);

    expect(fn () => ($this->start)($this->joy, $task))->toThrow(ActionRefused::class, 'no longer waiting');
    $task->refresh();
    expect($task->cleaned_by)->toBe($this->dodong->id)
        ->and($task->status)->toBe(CleaningStatus::InProgress)
        ->and(Hotel::room('205')->status)->toBe(RoomStatus::Cleaning);
});

// With several rooms waiting on one floor, the queue decides the order: oldest first, only the first
// N startable (N = roomboys online). Once one roomboy takes the top room it leaves the queue, so the
// other takes the next — they never collide and never skip ahead.
it('hands the next room to the other roomboy once the first is taken', function (): void {
    $r1 = ($this->leave)('205');
    $r2 = ($this->leave)('206');
    $r3 = ($this->leave)('207');
    ($this->online)($this->dodong, $this->joy); // heartbeat after the check-outs advanced the clock

    expect(app(CleaningQuery::class)->startableIds($this->dodong))->toBe([$r1->id, $r2->id]); // two online → first two

    ($this->start)($this->dodong, $r1);

    $joyStartable = app(CleaningQuery::class)->startableIds($this->joy);
    expect($joyStartable)->toContain($r2->id)->not->toContain($r1->id); // r1 is gone, r2 is next

    ($this->start)($this->joy, $r2);
    expect($r2->fresh()->cleaned_by)->toBe($this->joy->id)
        ->and($r3->fresh()->status)->toBe(CleaningStatus::Queued); // still waiting for whoever frees up first
});

// A room is only offered to roomboys assigned to its floor (Dodong 1–2, Joy 2–3).
it('keeps a room off the queue of a roomboy not assigned to its floor', function (): void {
    $suite = ($this->leave)('301'); // floor 3 — Joy's, not Dodong's
    ($this->online)($this->dodong, $this->joy);

    expect(collect(app(CleaningQuery::class)->queue($this->dodong)['tasks'])->pluck('room'))->not->toContain('301');
    expect(fn () => ($this->start)($this->dodong, $suite))->toThrow(ActionRefused::class);

    expect(collect(app(CleaningQuery::class)->queue($this->joy)['tasks'])->pluck('room'))->toContain('301');
    ($this->start)($this->joy, $suite);
    expect($suite->fresh()->cleaned_by)->toBe($this->joy->id);
});
