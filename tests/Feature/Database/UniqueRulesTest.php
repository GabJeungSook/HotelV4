<?php

declare(strict_types=1);

use App\Enums\CleaningStatus;
use App\Enums\ShiftStatus;
use App\Enums\StayState;
use App\Models\Branch;
use App\Models\CleaningTask;
use App\Models\DayClosing;
use App\Models\PinLock;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Database\QueryException;

/*
| The "one at a time" rules are generated columns with a UNIQUE index
| (DATABASE_SCHEMA §1). Each test makes MySQL refuse a violating insert.
*/

function expectDuplicate(string $key, Closure $insert): void
{
    try {
        $insert();
    } catch (QueryException $e) {
        expect($e->errorInfo[1] ?? null)->toBe(1062)
            ->and($e->getMessage())->toContain($key);

        return;
    }

    test()->fail("Expected a duplicate-key error on {$key}.");
}

it('refuses a second active stay in a room', function (): void {
    $stay = Stay::factory()->create();

    expectDuplicate('stays_one_active_per_room', fn () => Stay::factory()->held()->create([
        'branch_id' => $stay->branch_id,
        'room_id' => $stay->room_id,
    ]));
});

it('lets a room take a new stay once the old one is checked out', function (): void {
    $old = Stay::factory()->checkedOut()->create();
    $new = Stay::factory()->create(['branch_id' => $old->branch_id, 'room_id' => $old->room_id]);

    expect($new->fresh()?->active_room_id)->toBe($old->room_id)
        ->and($old->fresh()?->active_room_id)->toBeNull();
});

it('refuses a second open shift in a branch, closing counts as open', function (ShiftStatus $first): void {
    $shift = ShiftSession::factory()->create(['status' => $first]);

    expectDuplicate('shift_sessions_one_open', fn () => ShiftSession::factory()->create(['branch_id' => $shift->branch_id]));
})->with([ShiftStatus::Open, ShiftStatus::Closing]);

it('allows any number of closed shifts beside the open one', function (): void {
    $branch = Branch::factory()->create();
    ShiftSession::factory()->closed(1)->create(['branch_id' => $branch->id]);
    ShiftSession::factory()->closed(2)->create(['branch_id' => $branch->id]);
    $open = ShiftSession::factory()->create(['branch_id' => $branch->id]);

    expect($open->fresh()?->open_branch_id)->toBe($branch->id);
});

it('refuses a second in-progress cleaning task for one housekeeper', function (): void {
    $task = CleaningTask::factory()->inProgress()->create();

    expectDuplicate('cleaning_tasks_one_per_cleaner', fn () => CleaningTask::factory()->inProgress($task->cleaned_by)->create());
});

it('refuses a second open cleaning task for one room', function (): void {
    $task = CleaningTask::factory()->create();
    $nextStay = Stay::factory()->checkedOut()->create(['branch_id' => $task->branch_id, 'room_id' => $task->room_id]);

    expectDuplicate('cleaning_tasks_one_open_per_room', fn () => CleaningTask::factory()->create(['stay_id' => $nextStay->id]));

    $task->update(['status' => CleaningStatus::Done, 'finished_at' => '2026-11-11 11:20:00', 'cleaned_by' => User::factory()->housekeeper()->create(['branch_id' => $task->branch_id])->id]);
    expect(CleaningTask::factory()->create(['stay_id' => $nextStay->id])->exists)->toBeTrue();
});

it('refuses a repeated Z number in a branch', function (): void {
    $shift = ShiftSession::factory()->closed(629)->create();

    expectDuplicate('shift_sessions_z_unique', fn () => ShiftSession::factory()->closed(629)->create(['branch_id' => $shift->branch_id]));
});

it('allows the same Z number in another branch', function (): void {
    ShiftSession::factory()->closed(629)->create();

    expect(ShiftSession::factory()->closed(629)->create()->z_no)->toBe(629);
});

it('refuses a second day closing for a date', function (): void {
    $day = DayClosing::factory()->create();

    expectDuplicate('day_closings_date_unique', fn () => DayClosing::factory()->create([
        'branch_id' => $day->branch_id, 'business_date' => $day->business_date, 'day_z_no' => 2,
    ]));
});

it('refuses a repeated day Z number in a branch', function (): void {
    $day = DayClosing::factory()->create();

    expectDuplicate('day_closings_no_unique', fn () => DayClosing::factory()->create([
        'branch_id' => $day->branch_id, 'business_date' => '2026-11-11', 'day_z_no' => $day->day_z_no,
    ]));
});

it('refuses a second open PIN lock for a computer, but allows one after the unlock', function (): void {
    $lock = PinLock::factory()->create();

    expectDuplicate('pin_locks_one_open', fn () => PinLock::factory()->create(['device_key' => $lock->device_key]));

    $lock->update(['unlocked_at' => '2026-11-11 01:05:00', 'unlocked_by' => User::factory()->supervisor()->create()->id]);
    expect(PinLock::factory()->create(['device_key' => $lock->device_key])->exists)->toBeTrue();
});

it('refuses a repeated idempotency key', function (): void {
    $stay = Stay::factory()->create(['idempotency_key' => '4f1c2a80-6b1e-4c8e-9a51-0d3c2b7e1a11']);

    expectDuplicate('stays_idempotency_unique', fn () => Stay::factory()->create([
        'branch_id' => $stay->branch_id, 'idempotency_key' => $stay->idempotency_key,
    ]));
});

it('fills the generated columns from the row state', function (): void {
    $stay = Stay::factory()->held()->create();
    expect($stay->fresh()?->active_room_id)->toBe($stay->room_id);

    $stay->update(['state' => StayState::Expired]);
    expect($stay->fresh()?->active_room_id)->toBeNull();
});
