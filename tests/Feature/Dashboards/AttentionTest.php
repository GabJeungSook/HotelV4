<?php

declare(strict_types=1);

use App\Actions\Housekeeping\ResetCleaning;
use App\Actions\Housekeeping\StartCleaning;
use App\Actions\Shifts\HandOverShift;
use App\Actions\Stays\CheckOutGuest;
use App\Enums\CleaningStatus;
use App\Enums\PaymentMethod;
use App\Enums\RoomStatus;
use App\Enums\ShiftStatus;
use App\Exceptions\ActionRefused;
use App\Models\AuditLog;
use App\Models\CleaningTask;
use App\Models\Product;
use App\Models\ShiftSession;
use App\Models\User;
use App\Queries\StayQuery;
use App\Services\AttentionList;
use App\Support\Clock;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| AttentionList (TRD §4.11, screen 49): one class builds every row with a severity, a sentence
| and a route. ResetCleaning and ForceCloseShift are posted from 49 (TRD §4.9).
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-10 00:00:00'); // 8:00 AM Manila
});

function kinds(array $rows): array
{
    return array_values(array_unique(array_column($rows, 'kind')));
}

function stuckCleaning(): CleaningTask
{
    $stay = Hotel::checkIn('101', 6);
    $due = app(StayQuery::class)->balanceDue($stay->id);
    app(CheckOutGuest::class)->handle(Hotel::frontdesk(), $stay, payWith: PaymentMethod::Cash, amountGiven: $due->isZero() ? null : $due);
    $task = CleaningTask::query()->where('stay_id', $stay->id)->firstOrFail();

    return app(StartCleaning::class)->handle(Hotel::user('dodong.lumayag'), $task);
}

it('lists overdue stays, a shift open too long and a stuck cleaning, high first, each with a route', function (): void {
    Hotel::openShift();
    Hotel::checkIn('205', 6, guest: 'Dennis Abella');
    stuckCleaning();
    $this->travelClock(14 * 60 + 5); // 10:05 PM: the stay is 8 h over, the AM shift 2 h past its 8 PM end

    $rows = app(AttentionList::class)->rows([Hotel::branch()->id], Hotel::user('ramil.ortega'));

    expect(kinds($rows))->toContain('overdue_stay', 'shift_open_too_long', 'cleaning_stuck', 'no_pin')
        ->and($rows[0]['severity'])->toBe('high')
        ->and($rows[0]['kind'])->toBe('overdue_stay')
        ->and($rows[0]['title'])->toStartWith('Room 205 overdue')
        ->and($rows[0]['detail'])->toContain('Dennis Abella');

    foreach ($rows as $row) {
        expect($row['title'])->not->toBe('')->and($row['route'])->toStartWith('/')->and($row['severity'])->toBeIn(['high', 'normal']);
    }

    $force = collect($rows)->firstWhere('kind', 'shift_open_too_long');
    expect($force['action']['type'])->toBe('force_close');
});

it('does not list a stay before the overdue threshold or a cleaning before 60 minutes', function (): void {
    Hotel::openShift();
    Hotel::checkIn('205', 6);
    stuckCleaning();
    $this->travelClock(30); // the cleaning is 30 min in, the stay is not yet due

    expect(kinds(app(AttentionList::class)->rows([Hotel::branch()->id], Hotel::user('ramil.ortega'))))->not->toContain('overdue_stay', 'cleaning_stuck');

    $this->travelClock(6 * 60); // the stay is 30 min over: still under the 60-minute threshold
    expect(kinds(app(AttentionList::class)->rows([Hotel::branch()->id], Hotel::user('ramil.ortega'))))->not->toContain('overdue_stay');
});

it('lists a day not yet closed, a short shift and a branch with no open shift', function (): void {
    Hotel::openShift();
    Hotel::checkIn('205', 24);
    Hotel::closeToStep4(offset: -8000);
    app(HandOverShift::class)->withoutReceiver(Hotel::frontdesk(), Hotel::branch()->id, Hotel::supervisor(), Hotel::PIN, Hotel::device());
    $this->travelClock(24 * 60);

    $rows = app(AttentionList::class)->rows([Hotel::branch()->id], Hotel::user('grace.tolentino'));

    expect(kinds($rows))->toContain('day_not_closed', 'short_shift', 'no_open_shift');
    expect(collect($rows)->firstWhere('kind', 'no_open_shift')['severity'])->toBe('high'); // a guest is in house
    expect(collect($rows)->firstWhere('kind', 'day_not_closed')['route'])->toContain('/reports/daily/close');
    expect(collect($rows)->firstWhere('kind', 'short_shift')['title'])->toContain('was short ₱80.00');
});

it('lists failed invariants from the nightly check', function (): void {
    AuditLog::query()->create(['action' => 'invariant.failed', 'subject_type' => 'invariant', 'subject_id' => 2, 'after_json' => ['message' => '2: Room 205 is occupied with no stay'], 'created_at' => app(Clock::class)->now()]);

    $rows = app(AttentionList::class)->rows([Hotel::branch()->id], Hotel::user('ramil.ortega'));

    expect(collect($rows)->firstWhere('kind', 'invariant_failed'))->not->toBeNull()
        ->and($rows[0]['kind'])->toBe('invariant_failed');
});

it('shows "All good." data as an empty list when nothing needs attention', function (): void {
    User::query()->whereNull('pin_hash')->update(['pin_hash' => bcrypt('1234')]);
    Product::query()->update(['stock_on_hand' => 100]);
    Hotel::openShift();

    expect(app(AttentionList::class)->rows([Hotel::branch()->id], Hotel::user('ramil.ortega')))->toBe([]);
});

it('resets a stuck cleaning: the task is reset, a new one is queued with the same deadline, the room is to clean', function (): void {
    Hotel::openShift();
    $task = stuckCleaning();
    $this->travelClock(70);

    $this->actingAs(Hotel::supervisor())->post(route('housekeeping.reset', $task->id))->assertRedirect(route('admin.exceptions'));

    $fresh = CleaningTask::query()->where('room_id', $task->room_id)->where('status', CleaningStatus::Queued)->firstOrFail();
    expect($task->fresh()->status)->toBe(CleaningStatus::Reset)
        ->and($fresh->queued_at->equalTo($task->queued_at))->toBeTrue()
        ->and($fresh->due_at->equalTo($task->due_at))->toBeTrue()
        ->and(Hotel::room('101')->status)->toBe(RoomStatus::Uncleaned)
        ->and(AuditLog::query()->where('action', 'cleaning.reset')->exists())->toBeTrue();

    expect(fn () => app(ResetCleaning::class)->handle(Hotel::supervisor(), $task->fresh()))->toThrow(ActionRefused::class);
});

it('force closes a shift left open from 49 with a count and a PIN', function (): void {
    $shift = Hotel::openShift();
    Hotel::checkIn('205', 24);
    $this->travelClock(14 * 60 + 5);
    $expected = Hotel::expectedCash()->centavos();

    $this->actingAs(Hotel::user('ramil.ortega'))->post(route('shift.force-close', $shift->id), [
        'pieces' => ['coins' => $expected - 14500], /* short ₱145: a force close needs no variance note */ 'approver_id' => Hotel::supervisor()->id, 'pin' => Hotel::PIN,
    ])->assertRedirect(route('admin.exceptions'));

    $closed = ShiftSession::query()->findOrFail($shift->id);
    expect($closed->status)->toBe(ShiftStatus::Closed)->and($closed->is_force_closed)->toBeTrue()
        ->and($closed->variance_amount?->centavos())->toBe(-14500);
});

it('opens 49 for admin and supervisor but not back office or front desk', function (): void {
    $this->actingAs(Hotel::user('ramil.ortega'))->get('/admin/exceptions')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Exceptions')->has('rows'));
    $this->actingAs(Hotel::supervisor())->get('/admin/exceptions')->assertOk();
    $this->actingAs(Hotel::user('grace.tolentino'))->get('/admin/exceptions')->assertForbidden();
    $this->actingAs(Hotel::frontdesk())->get('/admin/exceptions')->assertForbidden();
});
