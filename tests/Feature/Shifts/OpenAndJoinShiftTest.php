<?php

declare(strict_types=1);

use App\Actions\Shifts\JoinShift;
use App\Actions\Shifts\OpenShift;
use App\Enums\ApprovalAction;
use App\Enums\ShiftStatus;
use App\Exceptions\ActionRefused;
use App\Exceptions\NoOpenShift;
use App\Exceptions\ShiftAlreadyOpen;
use App\Exceptions\ShiftFull;
use App\Models\Approval;
use App\Models\ShiftDefinition;
use App\Models\ShiftHandover;
use App\Models\ShiftMember;
use App\Models\ShiftSession;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Tests\Support\Hotel;

/*
| OpenShift (2) and JoinShift (2b): APP_FLOW §4.2–4.3, TRD §4.6, the Shifts tests of TRD §10
| that these two actions cover.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00'); // 9:00 AM in Manila
    $this->branch = Hotel::branch();
});

function closedShift(string $period, string $date, int $counted = 500000): ShiftSession
{
    $definition = ShiftDefinition::query()->where('name', $period)->firstOrFail();
    $shift = ShiftSession::factory()->closed()->create([
        'branch_id' => Hotel::branch()->id,
        'shift_definition_id' => $definition->id,
        'period_name' => $period,
        'business_date' => $date,
        'opened_by' => Hotel::user('mark.sumagaysay')->id,
        'opening_cash' => $counted,
        'expected_cash' => $counted,
        'counted_cash' => $counted,
    ]);
    ShiftHandover::query()->create(['from_shift_id' => $shift->id, 'created_at' => '2026-11-11 12:00:00']);

    return $shift;
}

it('opens the first shift on the period running now, with the opener as member', function (): void {
    $shift = Hotel::openShift();

    expect($shift->status)->toBe(ShiftStatus::Open)
        ->and($shift->period_name)->toBe('AM')
        ->and($shift->business_date->toDateString())->toBe('2026-11-12')
        ->and($shift->opening_cash->centavos())->toBe(500000)
        ->and($shift->opened_by)->toBe(Hotel::frontdesk()->id)
        ->and(ShiftMember::query()->where('shift_session_id', $shift->id)->pluck('user_id')->all())->toBe([Hotel::frontdesk()->id]);
});

it('gives a PM shift opened after midnight the business date it started on', function (): void {
    $this->freezeClock('2026-11-12 16:10:00'); // 12:10 AM on 13 Nov in Manila

    $shift = Hotel::openShift();

    expect($shift->period_name)->toBe('PM')->and($shift->business_date->toDateString())->toBe('2026-11-12');
});

it('refuses a second open shift', function (): void {
    Hotel::openShift();

    Hotel::openShift(Hotel::user('rhea.bautista'));
})->throws(ShiftAlreadyOpen::class);

it('has the database refuse a second open shift even past the check', function (): void {
    $first = Hotel::openShift();

    expect(fn () => ShiftSession::query()->create([
        'branch_id' => $first->branch_id, 'shift_definition_id' => ShiftDefinition::query()->where('name', 'PM')->value('id'), 'period_name' => 'PM',
        'business_date' => '2026-11-12', 'status' => ShiftStatus::Open, 'opened_at' => '2026-11-12 01:00:00',
        'opened_by' => Hotel::user('rhea.bautista')->id, 'opening_cash' => 0,
    ]))->toThrow(QueryException::class, 'shift_sessions_one_open');
});

it('proposes the period after the last closed shift and links the handover left without a receiver', function (): void {
    $am = closedShift('AM', '2026-11-12', counted: 480000);

    $shift = app(OpenShift::class)->handle(Hotel::frontdesk(), $this->branch->id, Money::ofCentavos(480000));

    $handover = ShiftHandover::query()->where('from_shift_id', $am->id)->sole();
    expect($shift->period_name)->toBe('PM')
        ->and($shift->business_date->toDateString())->toBe('2026-11-12')
        ->and($handover->to_shift_id)->toBe($shift->id)
        ->and($handover->received_by)->toBe(Hotel::frontdesk()->id)
        ->and($handover->received_cash->centavos())->toBe(480000)
        ->and($handover->difference_amount->isZero())->toBeTrue();
});

it('wraps from the last period of a day to the first period of the next', function (): void {
    closedShift('PM', '2026-11-11');

    $shift = Hotel::openShift();

    expect($shift->period_name)->toBe('AM')->and($shift->business_date->toDateString())->toBe('2026-11-12');
});

it('needs a note when the drawer count differs from the last count, and records the difference', function (): void {
    $am = closedShift('AM', '2026-11-12', counted: 480000);
    $open = fn (?string $note) => app(OpenShift::class)->handle(Hotel::frontdesk(), $this->branch->id, Money::ofCentavos(470000), $note);

    expect(fn () => $open(null))->toThrow(ActionRefused::class, 'A note is needed');

    $shift = $open('₱100 short in the drawer');
    $handover = ShiftHandover::query()->where('from_shift_id', $am->id)->sole();

    expect($shift->opening_note)->toBe('₱100 short in the drawer')
        ->and($handover->difference_amount->centavos())->toBe(-10000)
        ->and($handover->note)->toBe('₱100 short in the drawer');
});

it('needs a supervisor PIN to change the proposed period once a shift has been closed', function (): void {
    closedShift('AM', '2026-11-12');
    $am = ShiftDefinition::query()->where('name', 'AM')->firstOrFail();
    $open = fn (array $pin) => app(OpenShift::class)->handle(Hotel::frontdesk(), $this->branch->id, Money::ofCentavos(500000), null, $am->id, '2026-11-13', ...$pin);

    expect(fn () => $open([]))->toThrow(ActionRefused::class, 'needs a supervisor PIN');

    $shift = $open([Hotel::supervisor(), Hotel::PIN, Hotel::device()]);
    $approval = Approval::query()->sole();

    expect($shift->period_name)->toBe('AM')
        ->and($shift->business_date->toDateString())->toBe('2026-11-13')
        ->and($approval->action)->toBe(ApprovalAction::ChangeShiftPeriod)
        ->and($approval->subject_id)->toBe($shift->id)
        ->and($approval->approved_by)->toBe(Hotel::supervisor()->id);
});

it('lets a second front desk join, and refuses a third', function (): void {
    $shift = Hotel::openShift();
    $join = app(JoinShift::class);

    $member = $join->handle(Hotel::user('rhea.bautista'), $this->branch->id);
    expect($member->shift_session_id)->toBe($shift->id);

    expect(fn () => $join->handle(Hotel::user('mark.sumagaysay'), $this->branch->id))
        ->toThrow(ShiftFull::class, 'This shift already has two front desks.');
    expect(ShiftMember::query()->where('shift_session_id', $shift->id)->count())->toBe(2);
});

it('does not count a supervisor toward the front desk limit, and joining twice changes nothing', function (): void {
    $shift = Hotel::openShift();
    $join = app(JoinShift::class);
    $join->handle(Hotel::user('rhea.bautista'), $this->branch->id);

    $join->handle(Hotel::supervisor(), $this->branch->id);
    $again = $join->handle(Hotel::user('rhea.bautista'), $this->branch->id);

    expect(ShiftMember::query()->where('shift_session_id', $shift->id)->count())->toBe(3)
        ->and($again->user_id)->toBe(Hotel::user('rhea.bautista')->id);
});

it('refuses to join when no shift is open', function (): void {
    app(JoinShift::class)->handle(Hotel::frontdesk(), $this->branch->id);
})->throws(NoOpenShift::class);
