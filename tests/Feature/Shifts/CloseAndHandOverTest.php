<?php

declare(strict_types=1);

use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\ForceCloseShift;
use App\Actions\Shifts\HandOverShift;
use App\Actions\Shifts\JoinShift;
use App\Actions\Shifts\OpenShift;
use App\Actions\Shifts\SaveCountDraft;
use App\Actions\Stays\AddCharges;
use App\Actions\Stays\CheckOutGuest;
use App\Actions\Stays\TakePayment;
use App\Actions\Stays\VoidLine;
use App\Enums\ApprovalAction;
use App\Enums\CashMovementKind;
use App\Enums\LineKind;
use App\Enums\PaymentMethod;
use App\Enums\ReasonKind;
use App\Enums\ShiftStatus;
use App\Exceptions\ActionRefused;
use App\Exceptions\ApprovalRefused;
use App\Exceptions\PinInvalid;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\CashCount;
use App\Models\CashCountDraft;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\ShiftForwardedStay;
use App\Models\ShiftHandover;
use App\Models\ShiftMember;
use App\Models\ShiftSession;
use App\Queries\ShiftQuery;
use App\Queries\ShiftScreenQuery;
use App\Services\CashMath;
use App\Services\CurrentShift;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\Hotel;

/*
| Close the shift and hand over (22–25): CloseShift, SaveCountDraft, HandOverShift and
| ForceCloseShift. APP_FLOW §4.16, DATABASE_SCHEMA §8.10, TRD §4.5, §4.10, §4.12.
| TRD §10 "Shifts": variance requires a note · close freezes totals; a later void posts to the
| new shift and the old totals are unchanged · handover opens the next shift atomically with the
| received cash · close without handover needs approval · forwarded-out of shift N equals
| forwarded-in of shift N+1 · charge in A paid in B · a shift 40 minutes late keeps all its
| transactions. "Shift safeguards": receiver cannot be the counter · count draft survives and is
| deleted at confirm · server total ignores a forged total. Z numbers and the grand total chain.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 11:00:00'); // 7:00 PM in Manila, AM shift (ends 8:00 PM)
    $this->branch = Hotel::branch();
    $this->branch->update(['denomination_count_enabled' => true]); // this suite exercises the by-denomination count
    $this->jessa = Hotel::frontdesk();
    $this->mark = Hotel::user('mark.sumagaysay');
    $this->shift = Hotel::openShift($this->jessa);
});

it('starts the close: the shift is closing and records who started it', function (): void {
    app(CloseShift::class)->begin($this->jessa, $this->branch->id);

    expect($this->shift->refresh()->status)->toBe(ShiftStatus::Closing)
        ->and(app(CurrentShift::class)->closerName($this->shift->id))->toBe('Jessa Mae');
});

it('starting the close twice is idempotent: recorded once, the first closer kept', function (): void {
    $close = app(CloseShift::class);
    $close->begin($this->jessa, $this->branch->id);
    $close->begin($this->mark, $this->branch->id); // a second tap, even by the other member

    expect($this->shift->refresh()->status)->toBe(ShiftStatus::Closing)
        ->and(AuditLog::query()->where('action', CurrentShift::CLOSING_STARTED)->where('subject_id', $this->shift->id)->count())->toBe(1)
        ->and(app(CurrentShift::class)->closerName($this->shift->id))->toBe('Jessa Mae');
});

it('adds up the pieces itself and ignores a forged total', function (): void {
    $close = app(CloseShift::class);
    $close->begin($this->jessa, $this->branch->id);

    $count = $close->count($this->jessa, $this->branch->id, ['100000' => 3, '50000' => '2', '2000' => 1, 'coins' => 450, 'total' => 999999999]);

    expect($count->total_cash?->centavos())->toBe(300000 + 100000 + 2000 + 450)
        ->and($count->denominations)->not->toHaveKey('total')
        ->and($count->counted_by)->toBe($this->jessa->id);
});

it('keeps one count per shift: a second confirm returns the first and changes nothing', function (): void {
    $close = app(CloseShift::class);
    $close->begin($this->jessa, $this->branch->id);
    $first = $close->count($this->jessa, $this->branch->id, ['coins' => 1000]);
    $second = $close->count($this->jessa, $this->branch->id, ['coins' => 5000]);

    expect($second->id)->toBe($first->id)->and($second->total_cash?->centavos())->toBe(1000)
        ->and(CashCount::query()->count())->toBe(1);
});

it('keeps the count draft between saves and deletes it when the count is confirmed', function (): void {
    $close = app(CloseShift::class);
    $close->begin($this->jessa, $this->branch->id);
    app(SaveCountDraft::class)->handle($this->jessa, $this->branch->id, ['100000' => 2, 'total' => 5]);
    app(SaveCountDraft::class)->handle($this->jessa, $this->branch->id, ['100000' => 4, '50000' => 1]);

    $draft = CashCountDraft::query()->findOrFail($this->shift->id);
    expect($draft->denominations['100000'])->toBe(4)->and($draft->denominations['50000'])->toBe(1)
        ->and($draft->denominations)->not->toHaveKey('total');

    $close->count($this->jessa, $this->branch->id, $draft->denominations);
    expect(CashCountDraft::query()->count())->toBe(0);
    expect(fn () => app(SaveCountDraft::class)->handle($this->jessa, $this->branch->id, ['100000' => 1]))->toThrow(ActionRefused::class);
});

it('reopens the count with a supervisor PIN so a typo can be re-entered', function (): void {
    $close = app(CloseShift::class);
    $close->begin($this->jessa, $this->branch->id);
    $close->count($this->jessa, $this->branch->id, ['100000' => 9]); // typo: ₱900,000
    $close->result($this->jessa, $this->branch->id, Hotel::reason(ReasonKind::Variance, 'Wrong change given'), 'Typo in the count');

    $close->reopenCount($this->jessa, $this->branch->id, Hotel::supervisor(), Hotel::PIN, Hotel::device());

    expect(CashCount::query()->where('shift_session_id', $this->shift->id)->count())->toBe(0)
        ->and($this->shift->refresh()->expected_cash)->toBeNull()
        ->and($this->shift->counted_cash)->toBeNull()
        ->and(app(ShiftScreenQuery::class)->step($this->shift))->toBe(2)
        ->and(Approval::query()->where('action', ApprovalAction::RedoCount)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'shift.count_reopened')->count())->toBe(1);

    // The count can be entered again cleanly.
    $again = $close->count($this->jessa, $this->branch->id, ['coins' => 2000]);
    expect($again->total_cash?->centavos())->toBe(2000);
});

it('refuses to reopen the count before one exists, or on a wrong PIN', function (): void {
    $close = app(CloseShift::class);
    $close->begin($this->jessa, $this->branch->id);

    expect(fn () => $close->reopenCount($this->jessa, $this->branch->id, Hotel::supervisor(), Hotel::PIN, Hotel::device()))
        ->toThrow(ActionRefused::class);

    $close->count($this->jessa, $this->branch->id, ['coins' => 1000]);
    expect(fn () => $close->reopenCount($this->jessa, $this->branch->id, Hotel::supervisor(), '0000', Hotel::device()))
        ->toThrow(PinInvalid::class);
    expect(CashCount::query()->where('shift_session_id', $this->shift->id)->count())->toBe(1); // nothing removed
});

it('lets "Not yet" reopen the shift only before the count is saved', function (): void {
    $close = app(CloseShift::class);
    $close->begin($this->jessa, $this->branch->id);
    $close->notYet($this->jessa, $this->branch->id);
    expect($this->shift->refresh()->status)->toBe(ShiftStatus::Open);

    $close->begin($this->jessa, $this->branch->id);
    $close->count($this->jessa, $this->branch->id, ['coins' => 100]);

    expect(fn () => $close->notYet($this->jessa, $this->branch->id))->toThrow(ActionRefused::class);
    expect($this->shift->refresh()->status)->toBe(ShiftStatus::Closing);
});

it('requires a reason and its note when the count is short or over, and stores the variance', function (): void {
    Hotel::checkIn();
    $close = app(CloseShift::class);
    $close->begin($this->jessa, $this->branch->id);
    $expected = Hotel::expectedCash()->centavos();
    $close->count($this->jessa, $this->branch->id, ['coins' => $expected - 5000]);
    $reason = Hotel::reason(ReasonKind::Variance, 'Wrong change given');

    expect(fn () => $close->result($this->jessa, $this->branch->id))->toThrow(ActionRefused::class);
    expect(fn () => $close->result($this->jessa, $this->branch->id, $reason, ''))->toThrow(ActionRefused::class);

    $shift = $close->result($this->jessa, $this->branch->id, $reason, 'Gave wrong change to Room 203');

    expect($shift->expected_cash?->centavos())->toBe($expected)
        ->and($shift->counted_cash?->centavos())->toBe($expected - 5000)
        ->and($shift->variance_amount?->centavos())->toBe(-5000)
        ->and($shift->variance_reason_id)->toBe($reason->id)
        ->and($shift->variance_note)->toBe('Wrong change given — Gave wrong change to Room 203')
        ->and($shift->status)->toBe(ShiftStatus::Closing);
});

it('hands over in one transaction: the shift closes and the next opens with the cash the receiver counted', function (): void {
    Hotel::checkIn();
    $next = Hotel::closeAndHandOver($this->mark);
    $old = $this->shift->refresh();

    expect($old->status)->toBe(ShiftStatus::Closed)
        ->and($old->closed_by)->toBe($this->jessa->id)
        ->and($old->z_no)->toBe(1)
        ->and($old->totals)->toBeArray()->toHaveKeys(['sales', 'collected', 'deposits', 'unpaid', 'guests', 'cash', 'exceptions', 'by_user', 'documents'])
        ->and($old->handover_note)->toBe('Handover note')
        ->and($next->status)->toBe(ShiftStatus::Open)
        ->and($next->period_name)->toBe('PM')
        ->and($next->business_date->toDateString())->toBe('2026-11-12')
        ->and($next->opening_cash?->centavos())->toBe($old->counted_cash?->centavos())
        ->and($next->opened_by)->toBe($this->mark->id)
        ->and(ShiftMember::query()->where('shift_session_id', $next->id)->pluck('user_id')->all())->toBe([$this->mark->id]);

    $handover = ShiftHandover::query()->where('from_shift_id', $old->id)->firstOrFail();
    expect($handover->to_shift_id)->toBe($next->id)
        ->and($handover->received_by)->toBe($this->mark->id)
        ->and($handover->difference_amount?->centavos())->toBe(0);
});

it('needs a note when the receiver counts a different amount, and keeps the difference on the handover', function (): void {
    Hotel::closeToStep4($this->jessa);
    $counted = $this->shift->refresh()->counted_cash?->centavos() ?? 0;
    $hand = fn (?string $note) => app(HandOverShift::class)->toReceiver($this->jessa, $this->branch->id, $this->mark, Hotel::PIN, Money::ofCentavos($counted - 2000), Hotel::device(), null, $note);

    expect(fn () => $hand(null))->toThrow(ActionRefused::class);
    expect($this->shift->refresh()->status)->toBe(ShiftStatus::Closing);

    $next = $hand('Drawer has ₱20 less than the count');
    $handover = ShiftHandover::query()->where('to_shift_id', $next->id)->firstOrFail();

    expect($next->opening_cash?->centavos())->toBe($counted - 2000)
        ->and($handover->difference_amount?->centavos())->toBe(-2000)
        ->and($handover->note)->toBe('Drawer has ₱20 less than the count');
});

it('refuses the person who counted as receiver', function (): void {
    app(JoinShift::class)->handle(Hotel::user('rhea.bautista'), $this->branch->id);
    Hotel::closeToStep4($this->jessa);

    expect(fn () => app(HandOverShift::class)->toReceiver($this->jessa, $this->branch->id, $this->jessa, Hotel::PIN, Money::zero()->plus($this->shift->refresh()->counted_cash ?? Money::zero()), Hotel::device()))
        ->toThrow(ActionRefused::class, 'The person who counted the cash cannot receive the shift.');

    // The partner who did not count may receive.
    $next = app(HandOverShift::class)->toReceiver($this->jessa, $this->branch->id, Hotel::user('rhea.bautista'), Hotel::PIN, $this->shift->refresh()->counted_cash ?? Money::zero(), Hotel::device());
    expect($next->opened_by)->toBe(Hotel::user('rhea.bautista')->id);
});

it('changes nothing when the receiver types a wrong PIN', function (): void {
    Hotel::closeToStep4($this->jessa);

    expect(fn () => app(HandOverShift::class)->toReceiver($this->jessa, $this->branch->id, $this->mark, '9999', $this->shift->refresh()->counted_cash ?? Money::zero(), Hotel::device()))
        ->toThrow(PinInvalid::class);
    expect($this->shift->refresh()->status)->toBe(ShiftStatus::Closing)
        ->and($this->shift->z_no)->toBeNull()
        ->and(ShiftSession::query()->count())->toBe(1)
        ->and(ShiftForwardedStay::query()->count())->toBe(0)
        ->and($this->branch->refresh()->next_z_number)->toBe(1);
});

it('closes without handover only with a supervisor PIN, and the next Open shift picks up the counted cash', function (): void {
    Hotel::checkIn();
    Hotel::closeToStep4($this->jessa, offset: -5000);
    $without = fn ($approver) => app(HandOverShift::class)->withoutReceiver($this->jessa, $this->branch->id, $approver, Hotel::PIN, Hotel::device(), 'Nobody came');

    expect(fn () => $without($this->mark))->toThrow(ApprovalRefused::class);
    expect($this->shift->refresh()->status)->toBe(ShiftStatus::Closing);

    $closed = $without(Hotel::supervisor());

    expect($closed->status)->toBe(ShiftStatus::Closed)
        ->and($closed->z_no)->toBe(1)
        ->and(ShiftHandover::query()->where('from_shift_id', $closed->id)->value('to_shift_id'))->toBeNull()
        ->and(Approval::query()->where('action', ApprovalAction::CloseWithoutHandover)->where('subject_id', $closed->id)->exists())->toBeTrue()
        ->and(Approval::query()->where('action', ApprovalAction::ApproveVariance)->where('subject_id', $closed->id)->value('approved_by'))->toBe(Hotel::supervisor()->id);

    $screen = app(ShiftQuery::class)->openScreen($this->branch->id);
    expect($screen['last_counted'])->toBe($closed->counted_cash?->centavos());

    $next = app(OpenShift::class)->handle($this->mark, $this->branch->id, $closed->counted_cash ?? Money::zero());
    expect(ShiftHandover::query()->where('from_shift_id', $closed->id)->value('to_shift_id'))->toBe($next->id);
});

it('refuses the counter as approver of a close without handover', function (): void {
    Hotel::closeToStep4(Hotel::supervisor());

    expect(fn () => app(HandOverShift::class)->withoutReceiver(Hotel::supervisor(), $this->branch->id, Hotel::supervisor(), Hotel::PIN, Hotel::device()))
        ->toThrow(ActionRefused::class, 'The person who counted the cash cannot approve this close.');
});

it('forwards every checked-in stay out of shift N and into shift N+1 with the same amounts', function (): void {
    Hotel::checkIn('205');
    $owing = Hotel::checkIn('206', guest: 'Nora Santos');
    app(AddCharges::class)->handle($this->jessa, $owing, [Hotel::product('Chips')->id => 2]);

    $b = Hotel::closeAndHandOver($this->mark);
    $a = $this->shift->refresh();
    $rows = ShiftForwardedStay::query()->where('shift_session_id', $a->id)->get();

    expect($rows)->toHaveCount(2)
        ->and($a->totals['guests']['forwarded_out'])->toBe(2)
        ->and($a->totals['unpaid']['forwarded_out'])->toBe((int) $rows->sum(fn ($r) => $r->unpaid_amount->centavos()))
        ->and($a->totals['unpaid']['forwarded_out'])->toBeGreaterThan(0);

    $this->travelClock(60);
    $c = Hotel::closeAndHandOver($this->jessa, $this->mark);
    $b->refresh();

    expect($b->totals['guests']['forwarded_in'])->toBe(2)
        ->and($b->totals['unpaid']['forwarded_in'])->toBe($a->totals['unpaid']['forwarded_out'])
        ->and($b->totals['deposits']['held_open'])->toBe($a->totals['deposits']['held_close'])
        ->and($c->status)->toBe(ShiftStatus::Open);
});

it('counts a charge made in shift A and paid in shift B as a sale in A and cash in B, and both unpaid roll-forwards balance', function (): void {
    $stay = Hotel::checkIn();
    app(AddCharges::class)->handle($this->jessa, $stay, [Hotel::product('Chips')->id => 2]);
    $charge = (int) FolioLine::query()->where('stay_id', $stay->id)->where('kind', LineKind::FoodDrink)->sum('amount');

    $b = Hotel::closeAndHandOver($this->mark);
    $a = $this->shift->refresh();
    $payment = app(TakePayment::class)->handle($this->mark, $stay->refresh(), PaymentMethod::Cash, Money::ofCentavos($charge));

    $this->travelClock(60);
    Hotel::closeAndHandOver($this->jessa, $this->mark);
    $b->refresh();

    expect($a->totals['sales']['food_drink'])->toBe($charge)
        ->and($a->totals['unpaid']['forwarded_out'])->toBe($charge)
        ->and($b->totals['sales']['food_drink'])->toBe(0)
        ->and($b->totals['collected']['cash'])->toBe($charge)
        ->and($payment->shift_session_id)->toBe($b->id)
        ->and($b->totals['unpaid']['forwarded_in'])->toBe($charge)
        ->and($b->totals['unpaid']['forwarded_out'])->toBe(0)
        ->and(app(CashMath::class)->rollForwardsBalance($a->totals))->toBe(['guests' => true, 'deposits' => true, 'unpaid' => true])
        ->and(app(CashMath::class)->rollForwardsBalance($b->totals))->toBe(['guests' => true, 'deposits' => true, 'unpaid' => true]);
});

it('counts an unclaimed key deposit once, as forfeited, so the deposits roll-forward balances', function (): void {
    $stay = Hotel::checkIn();
    Hotel::checkIn('206', guest: 'Nora Santos');
    app(CheckOutGuest::class)->handle($this->jessa, $stay, depositClaimed: false);

    Hotel::closeAndHandOver($this->mark);
    $d = $this->shift->refresh()->totals['deposits'];

    expect($d['taken'])->toBe(40000)
        ->and($d['forfeited'])->toBe(20000)
        ->and($d['applied'])->toBe(0)
        ->and($d['held_close'])->toBe(20000)
        ->and(app(CashMath::class)->rollForwardsBalance($this->shift->totals)['deposits'])->toBeTrue();
});

it('freezes the totals at close: a later void posts to the new shift and the old totals stay as they were', function (): void {
    $stay = Hotel::checkIn();
    app(AddCharges::class)->handle($this->jessa, $stay, [Hotel::product('Chips')->id => 2]);
    $b = Hotel::closeAndHandOver($this->mark);
    $frozen = $this->shift->refresh()->totals;

    $line = FolioLine::query()->where('stay_id', $stay->id)->where('kind', LineKind::FoodDrink)->firstOrFail();
    $reversal = app(VoidLine::class)->handle($this->mark, $line, Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount'), Hotel::supervisor(), Hotel::PIN, Hotel::device());

    expect($reversal->shift_session_id)->toBe($b->id)
        ->and($this->shift->refresh()->totals)->toBe($frozen);

    $this->travelClock(60);
    Hotel::closeAndHandOver($this->jessa, $this->mark);
    expect($b->refresh()->totals['sales']['food_drink'])->toBe($line->amount->negate()->centavos())
        ->and($b->grand_total_after?->centavos())->toBe(($this->shift->grand_total_after?->centavos() ?? 0) + $b->totals['sales']['total']);
});

it('gives consecutive Z numbers and chains the grand total, a force-closed shift included', function (): void {
    Hotel::checkIn('205');
    $b = Hotel::closeAndHandOver($this->mark);
    $a = $this->shift->refresh();

    $this->travelClock(60);
    Hotel::checkIn('206', by: $this->mark, guest: 'Nora Santos');
    $c = Hotel::closeAndHandOver($this->jessa, $this->mark);
    $b->refresh();

    $this->travelClock(60);
    Hotel::checkIn('207', guest: 'Carlo Go');
    $forced = app(ForceCloseShift::class)->handle(Hotel::supervisor(), $this->branch->id, ['coins' => Hotel::expectedCash()->centavos()], Hotel::user('ramil.ortega'), Hotel::PIN, Hotel::device());

    expect([$a->z_no, $b->z_no, $forced->z_no])->toBe([1, 2, 3])
        ->and($forced->id)->toBe($c->id)
        ->and($forced->is_force_closed)->toBeTrue()
        ->and($forced->totals['exceptions']['force_closed'])->toBeTrue()
        ->and($a->grand_total_before?->centavos())->toBe(0)
        ->and($a->grand_total_after?->centavos())->toBe($a->totals['sales']['total'])
        ->and($b->grand_total_before?->centavos())->toBe($a->grand_total_after?->centavos())
        ->and($b->grand_total_after?->centavos())->toBe(($b->grand_total_before?->centavos() ?? 0) + $b->totals['sales']['total'])
        ->and($forced->grand_total_before?->centavos())->toBe($b->grand_total_after?->centavos())
        ->and($this->branch->refresh()->next_z_number)->toBe(4)
        ->and(Approval::query()->where('action', ApprovalAction::ForceCloseShift)->where('subject_id', $forced->id)->exists())->toBeTrue()
        ->and(ShiftHandover::query()->where('from_shift_id', $forced->id)->value('to_shift_id'))->toBeNull();
});

it('keeps every transaction of a shift that runs 40 minutes late on that shift', function (): void {
    // The AM shift ends at 8:00 PM Manila (12:00 UTC); 40 minutes later it is still the AM shift.
    $this->freezeClock('2026-11-12 12:40:00');
    $stay = Hotel::checkIn();
    Hotel::movement(CashMovementKind::Expense, 1000);
    expect(app(ShiftQuery::class)->current($this->branch->id)['overdue'])->toBeTrue();

    $b = Hotel::closeAndHandOver($this->mark);

    expect(Payment::query()->where('stay_id', $stay->id)->pluck('shift_session_id')->unique()->all())->toBe([$this->shift->id])
        ->and($this->shift->refresh()->totals['guests']['checked_in'])->toBe(1)
        ->and($this->shift->totals['cash']['expenses'])->toBe(1000)
        ->and($b->period_name)->toBe('PM');
});
