<?php

declare(strict_types=1);

use App\Actions\Stays\AddCharges;
use App\Actions\Stays\CancelStay;
use App\Actions\Stays\TakePayment;
use App\Actions\Stays\VoidLine;
use App\Enums\ApprovalAction;
use App\Enums\DepositType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\ReasonKind;
use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Enums\StayState;
use App\Enums\StockMovementKind;
use App\Exceptions\ActionRefused;
use App\Models\Approval;
use App\Models\CleaningTask;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\RoomStatusLog;
use App\Models\StayRoom;
use App\Models\StockMovement;
use App\Queries\StayQuery;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\Hotel;

/*
| VoidLine and CancelStay (15 → 14): DATABASE_SCHEMA §8.9, APP_FLOW §4.11.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:12:00');
    $this->shift = Hotel::openShift();
    $this->stay = Hotel::checkIn('205', 12);
    $this->stays = app(StayQuery::class);
    $this->void = fn (FolioLine $line, string $reason = 'Typed wrong amount', ?string $note = null) => app(VoidLine::class)->handle(
        Hotel::frontdesk(), $line, Hotel::reason(ReasonKind::VoidLine, $reason), Hotel::supervisor(), Hotel::PIN, Hotel::device(), $note,
    );
});

it('voids a paid line: reversal on the open shift, cash refund, stock back', function (): void {
    $noodles = Hotel::product('Cup noodles');
    $order = app(AddCharges::class)->handle(Hotel::frontdesk(), $this->stay, [$noodles->id => 2], payWith: PaymentMethod::Cash, amountGiven: Money::ofCentavos(9000));
    $line = FolioLine::query()->where('pos_order_id', $order->id)->sole();

    $reversal = ($this->void)($line);

    $refund = Payment::query()->where('kind', PaymentKind::Refund)->sole();
    $approval = Approval::query()->sole();
    $back = StockMovement::query()->where('kind', StockMovementKind::SaleReversal)->sole();

    expect([$reversal->reversal_of_id, $reversal->quantity, $reversal->unit_amount->centavos(), $reversal->amount->centavos(), $reversal->shift_session_id, $reversal->approval_id])
        ->toBe([$line->id, -2, 4500, -9000, $this->shift->id, $approval->id])
        ->and($reversal->reversal_reason)->toBe('Typed wrong amount')
        ->and([$approval->action, $approval->subject_type, $approval->subject_id, $approval->reason_id])
        ->toBe([ApprovalAction::VoidLine, 'folio_line', $line->id, Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount')->id])
        ->and([$refund->amount->centavos(), $refund->method, $refund->allocations()->sole()->folio_line_id])->toBe([-9000, PaymentMethod::Cash, $reversal->id])
        ->and([$back->quantity, $back->balance_after])->toBe([2, 30])
        ->and($noodles->fresh()->stock_on_hand)->toBe(30)
        ->and($line->fresh()->amount->centavos())->toBe(9000)
        ->and(collect($this->stays->folio($this->stay->id)['lines'])->firstWhere('id', $line->id)['status'])->toBe('reversed')
        ->and($this->stays->balanceDue($this->stay->id)->isZero())->toBeTrue();
});

it('voids an unpaid line without a refund', function (): void {
    $order = app(AddCharges::class)->handle(Hotel::frontdesk(), $this->stay, [Hotel::product('Chips')->id => 1]);

    ($this->void)(FolioLine::query()->where('pos_order_id', $order->id)->sole());

    expect(Payment::query()->where('kind', PaymentKind::Refund)->count())->toBe(0)
        ->and($this->stays->balanceDue($this->stay->id)->isZero())->toBeTrue();
});

it('refuses to void a line twice or to void a void', function (): void {
    $order = app(AddCharges::class)->handle(Hotel::frontdesk(), $this->stay, [Hotel::product('Chips')->id => 1]);
    $line = FolioLine::query()->where('pos_order_id', $order->id)->sole();
    $reversal = ($this->void)($line);

    expect(fn () => ($this->void)($line))->toThrow(ActionRefused::class, 'already voided')
        ->and(fn () => ($this->void)($reversal))->toThrow(ActionRefused::class, 'A void cannot be voided.');
});

it('needs a note for a reason that asks for one', function (): void {
    $line = FolioLine::query()->where('stay_id', $this->stay->id)->firstOrFail();

    ($this->void)($line, 'Other');
})->throws(ActionRefused::class, 'A note is needed for Other.');

it('cancels a stay: reverses every line, refunds what was paid and every deposit, frees the room', function (): void {
    $stay = Hotel::checkIn('206', 12, tendered: 100000, keepChange: true, discount: Hotel::discount('Senior citizen'), guest: 'Ana Reyes');
    $noodles = Hotel::product('Cup noodles');
    app(AddCharges::class)->handle(Hotel::frontdesk(), $stay, [$noodles->id => 1]);
    app(TakePayment::class)->handle(Hotel::frontdesk(), $stay, PaymentMethod::Cash, Money::ofCentavos(4500));
    $cashBefore = (int) Payment::query()->where('stay_id', $stay->id)->where('method', PaymentMethod::Cash)->sum('amount');

    app(CancelStay::class)->handle(Hotel::frontdesk(), $stay, Hotel::reason(ReasonKind::CancelStay, 'Wrong room checked in'), Hotel::supervisor(), Hotel::PIN, Hotel::device());

    $stay->refresh();
    $originals = FolioLine::query()->where('stay_id', $stay->id)->whereNull('reversal_of_id')->count();
    $reversals = FolioLine::query()->where('stay_id', $stay->id)->whereNotNull('reversal_of_id')->count();

    expect($stay->state)->toBe(StayState::Cancelled)
        ->and($stay->closed_reason)->toBe('Wrong room checked in')
        ->and([$originals, $reversals])->toBe([3, 3])
        ->and((int) FolioLine::query()->where('stay_id', $stay->id)->sum('amount'))->toBe(0)
        ->and((int) Payment::query()->where('stay_id', $stay->id)->where('method', PaymentMethod::Cash)->sum('amount'))->toBe(0)
        ->and($cashBefore)->toBe(52000 + 20000 + 28000 + 4500)
        ->and($this->stays->balanceDue($stay->id)->isZero())->toBeTrue()
        ->and($this->stays->depositHeld($stay->id, DepositType::Key)->isZero())->toBeTrue()
        ->and($this->stays->depositHeld($stay->id, DepositType::Guest)->isZero())->toBeTrue()
        ->and($noodles->fresh()->stock_on_hand)->toBe(30)
        ->and(StayRoom::query()->where('stay_id', $stay->id)->whereNull('to_at')->count())->toBe(0)
        ->and(Hotel::room('206')->status)->toBe(RoomStatus::Available)
        ->and(RoomStatusLog::query()->where('room_id', Hotel::room('206')->id)->latest('id')->first()->reason)->toBe(RoomStatusReason::Cancel)
        ->and(CleaningTask::query()->count())->toBe(0)
        ->and(Approval::query()->sole()->action)->toBe(ApprovalAction::CancelStay);
});
