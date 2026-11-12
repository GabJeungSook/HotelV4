<?php

declare(strict_types=1);

use App\Actions\Stays\CheckOutGuest;
use App\Enums\CleaningStatus;
use App\Enums\RoomStatus;
use App\Models\CleaningTask;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| HousekeepingController (32–35): the queue and its heartbeat, start, the cleaning screen, finish
| (ready or problem), my cleans, validation and who may use them. The "Done when" chain — a
| check-out queues the room on the phone and "Room is ready" shows it Vacant on the board — is
| the last test. Rules: tests/Feature/Housekeeping/CleaningTest.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00');
    Hotel::openShift();
    $this->dodong = Hotel::user('dodong.lumayag');
    $this->leave = function (string $room): CleaningTask {
        $stay = Hotel::checkIn($room, 6);
        app(CheckOutGuest::class)->handle(Hotel::frontdesk(), $stay);

        return CleaningTask::query()->where('stay_id', $stay->id)->sole();
    };
});

it('shows the empty queue (35) and touches last_seen_at', function (): void {
    $this->actingAs($this->dodong)->get('/housekeeping')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Housekeeping/Queue')
        ->where('floors', 'Floors 1–2')
        ->where('user_name', 'Dodong Lumayag')
        ->where('tasks', [])
        ->where('current', null));

    expect($this->dodong->fresh()->last_seen_at?->toDateTimeString())->toBe('2026-11-12 01:00:00');
});

it('shows only rooms on the housekeeper’s floors, oldest first, the first one startable', function (): void {
    ($this->leave)('205');
    ($this->leave)('301');
    ($this->leave)('101');

    $this->actingAs($this->dodong)->get('/housekeeping')->assertInertia(fn (Assert $page) => $page
        ->has('tasks', 2)
        ->where('tasks.0.room', '205')
        ->where('tasks.0.startable', true)
        ->where('tasks.1.room', '101')
        ->where('tasks.1.startable', false)
        ->where('tasks.1.next_in_line', true)
        ->where('online', 1));
});

it('starts a task and opens the cleaning screen (33)', function (): void {
    $task = ($this->leave)('205');

    $this->actingAs($this->dodong)->post("/housekeeping/{$task->id}/start")->assertRedirect("/housekeeping/{$task->id}");

    $this->get("/housekeeping/{$task->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Housekeeping/Cleaning')
        ->where('task.room', '205')
        ->where('target_minutes', 15)
        ->has('checklist', 4));
    expect(Hotel::room('205')->status)->toBe(RoomStatus::Cleaning);
});

it('refuses to start a room on another housekeeper’s floors', function (): void {
    $task = ($this->leave)('301');

    $this->actingAs($this->dodong)->post("/housekeeping/{$task->id}/start")->assertForbidden();
    expect($task->fresh()->status)->toBe(CleaningStatus::Queued);
});

it('turns a refused start into an error on the queue', function (): void {
    $first = ($this->leave)('205');
    $second = ($this->leave)('206');

    $this->actingAs($this->dodong)->from('/housekeeping')->post("/housekeeping/{$second->id}/start")->assertRedirect('/housekeeping');

    expect(session('inertia.flash_data.error.message'))->toBe('Room 206 is not next in line yet.')
        ->and($first->fresh()->status)->toBe(CleaningStatus::Queued);
});

it('sends the cleaning screen of a task that is not yours back to the queue', function (): void {
    $task = ($this->leave)('205');

    $this->actingAs($this->dodong)->get("/housekeeping/{$task->id}")->assertRedirect('/housekeeping');
});

it('finishes with a problem report, and validates the note', function (): void {
    $task = ($this->leave)('205');
    $this->actingAs($this->dodong)->post("/housekeeping/{$task->id}/start");

    $this->post("/housekeeping/{$task->id}/finish", ['result' => 'problem'])->assertSessionHasErrors('problem_note');
    $this->post("/housekeeping/{$task->id}/finish", ['result' => 'maybe'])->assertSessionHasErrors('result');

    $this->post("/housekeeping/{$task->id}/finish", ['result' => 'problem', 'problem_note' => 'Aircon leaking'])->assertRedirect('/housekeeping');
    expect(Hotel::room('205')->status)->toBe(RoomStatus::Maintenance)
        ->and(session('inertia.flash_data.toast.message'))->toBe('Room 205 is sent to maintenance.');
});

it('shows my cleans for today (34)', function (): void {
    $task = ($this->leave)('205');
    $this->actingAs($this->dodong)->post("/housekeeping/{$task->id}/start");
    $this->travelClock(17);
    $this->post("/housekeeping/{$task->id}/finish", ['result' => 'ready']);

    $this->get('/housekeeping/history')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Housekeeping/History')
        ->where('count', 1)
        ->where('late', 0)
        ->where('average_minutes', 17)
        ->where('cleans.0.room', '205'));
});

it('refuses housekeeping to other roles and to a branch with housekeeping switched off', function (): void {
    $task = ($this->leave)('205');

    foreach (['jessa.delacruz', 'carol.villanueva', 'ramil.ortega'] as $username) {
        $this->actingAs(Hotel::user($username))->get('/housekeeping')->assertForbidden();
        $this->post("/housekeeping/{$task->id}/start")->assertForbidden();
    }

    Hotel::branch()->update(['housekeeping_enabled' => false]);
    $this->actingAs($this->dodong)->get('/housekeeping')->assertForbidden();
});

it('puts a checked-out room in the housekeeper’s queue, and "Room is ready" shows it Vacant on the board', function (): void {
    $stay = Hotel::checkIn('205', 6);
    $this->actingAs(Hotel::frontdesk())->post("/stays/{$stay->id}/checkout", ['key_returned' => true, 'remote_returned' => true, 'deposit_claimed' => true])->assertRedirect();
    expect(Hotel::room('205')->status)->toBe(RoomStatus::Uncleaned);

    $this->actingAs($this->dodong)->get('/housekeeping')->assertInertia(fn (Assert $page) => $page->where('tasks.0.room', '205')->where('tasks.0.startable', true));
    $task = CleaningTask::query()->sole();
    $this->post("/housekeeping/{$task->id}/start");
    $this->post("/housekeeping/{$task->id}/finish", ['result' => 'ready'])->assertRedirect('/housekeeping');
    expect(session('inertia.flash_data.toast.message'))->toBe('Room 205 is ready for the next guest.');

    // The board's next refresh (its deferred `board` prop, reloaded every 15 s) shows it Vacant.
    $floors = [];
    $this->actingAs(Hotel::frontdesk())->get('/board')->assertInertia(function (Assert $page) use (&$floors): void {
        $page->loadDeferredProps(function (Assert $reload) use (&$floors): void {
            $floors = $reload->toArray()['props']['board']['floors'];
        });
    });
    expect(collect($floors)->pluck('rooms')->flatten(1)->firstWhere('number', '205')['status'])->toBe('vacant');
});
