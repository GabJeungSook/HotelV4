<?php

declare(strict_types=1);

use App\Actions\Stays\TransferRoom;
use App\Enums\ApprovalAction;
use App\Enums\CleaningTrigger;
use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Exceptions\ActionRefused;
use App\Exceptions\RoomNotAvailable;
use App\Models\Approval;
use App\Models\CleaningTask;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\RoomStatusLog;
use App\Models\Stay;
use App\Models\StayRoom;
use App\Queries\StayQuery;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\Hotel;

/*
| TransferRoom (13 → 14): DATABASE_SCHEMA §8.6, APP_FLOW §4.10, TRD §4.4.
| Standard 12 h ₱500, Deluxe 12 h ₱650.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:12:00');
    $this->shift = Hotel::openShift();
    $this->stays = app(StayQuery::class);
    $this->transfer = fn (Stay $stay, string $to, mixed ...$more) => app(TransferRoom::class)->handle(
        Hotel::frontdesk(), $stay, Hotel::room($to), $more['reason'] ?? Hotel::transferReason(), Hotel::supervisor(), Hotel::PIN, Hotel::device(),
        ...array_diff_key($more, ['reason' => true]),
    );
});

it('upgrades: collects the difference and leaves the original room line untouched', function (): void {
    $stay = Hotel::checkIn('101', 12);
    $roomLine = FolioLine::query()->where('stay_id', $stay->id)->where('kind', LineKind::Room)->sole();
    $this->travelClock(30);

    ($this->transfer)($stay, '205', payWith: PaymentMethod::Cash, amountGiven: Money::ofCentavos(15000));

    $stay->refresh();
    $difference = FolioLine::query()->where('stay_id', $stay->id)->where('kind', LineKind::TransferDifference)->sole();
    $approval = Approval::query()->sole();
    $segments = StayRoom::query()->where('stay_id', $stay->id)->orderBy('id')->get();

    expect($roomLine->fresh()->only(['amount', 'room_id', 'description']))->toEqual($roomLine->only(['amount', 'room_id', 'description']))
        ->and([$difference->amount->centavos(), $difference->description, $difference->room_id, $difference->approval_id])
        ->toBe([15000, 'Transfer 101 → 205', Hotel::room('205')->id, $approval->id])
        ->and([$approval->action, $approval->summary, $approval->approved_by])->toBe([ApprovalAction::Transfer, '101 → 205 · +₱150.00', Hotel::supervisor()->id])
        ->and([$stay->room_id, $stay->room_type_id])->toBe([Hotel::room('205')->id, Hotel::room('205')->room_type_id])
        ->and($stay->expected_checkout_at->toDateTimeString())->toBe('2026-11-12 13:12:00')
        ->and($segments->map(fn (StayRoom $s): array => [$s->room_id, $s->to_at?->toDateTimeString(), $s->transfer_reason_id])->all())
        ->toBe([[Hotel::room('101')->id, '2026-11-12 01:42:00', Hotel::transferReason()->id], [Hotel::room('205')->id, null, null]])
        ->and($this->stays->balanceDue($stay->id)->isZero())->toBeTrue();

    $task = CleaningTask::query()->sole();
    expect(Hotel::room('101')->status)->toBe(RoomStatus::Uncleaned)
        ->and(Hotel::room('205')->status)->toBe(RoomStatus::Occupied)
        ->and([$task->room_id, $task->trigger_kind, $task->due_at->toDateTimeString()])->toBe([Hotel::room('101')->id, CleaningTrigger::TransferOut, '2026-11-12 05:42:00'])
        ->and(RoomStatusLog::query()->where('room_id', Hotel::room('205')->id)->sole()->reason)->toBe(RoomStatusReason::CheckIn)
        ->and(RoomStatusLog::query()->where('room_id', Hotel::room('101')->id)->latest('id')->first()->reason)->toBe(RoomStatusReason::TransferOut);
});

it('downgrades: the credit goes to the guest deposit and the drawer is unchanged', function (): void {
    $stay = Hotel::checkIn('205', 12);
    $cashBefore = (int) Payment::query()->where('method', PaymentMethod::Cash)->sum('amount');

    ($this->transfer)($stay, '101');

    $line = FolioLine::query()->where('stay_id', $stay->id)->where('kind', LineKind::TransferDifference)->sole();
    $refund = Payment::query()->where('kind', PaymentKind::Refund)->sole();
    $credit = Payment::query()->where('kind', PaymentKind::DepositIn)->where('deposit_type', DepositType::Guest)->sole();

    expect($line->amount->centavos())->toBe(-15000)
        ->and([$refund->amount->centavos(), $refund->method, $refund->allocations()->sole()->folio_line_id])->toBe([-15000, PaymentMethod::Cash, $line->id])
        ->and([$credit->amount->centavos(), $credit->note])->toBe([15000, 'transfer credit'])
        ->and((int) Payment::query()->where('method', PaymentMethod::Cash)->sum('amount'))->toBe($cashBefore)
        ->and($this->stays->depositHeld($stay->id, DepositType::Guest)->centavos())->toBe(15000)
        ->and($this->stays->balanceDue($stay->id)->isZero())->toBeTrue()
        ->and(Approval::query()->sole()->summary)->toBe('205 → 101 · −₱150.00');
});

it('writes no line for a room of the same price, and can send the old room to maintenance', function (): void {
    $stay = Hotel::checkIn('205', 12);

    ($this->transfer)($stay, '206', oldRoomToMaintenance: true);

    expect(FolioLine::query()->where('kind', LineKind::TransferDifference)->count())->toBe(0)
        ->and(Payment::query()->where('stay_id', $stay->id)->count())->toBe(2)
        ->and(Hotel::room('205')->status)->toBe(RoomStatus::Maintenance)
        ->and(CleaningTask::query()->count())->toBe(0)
        ->and(Hotel::room('206')->status)->toBe(RoomStatus::Occupied);
});

it('refuses a room that is not available and rolls the approval back with it', function (): void {
    $stay = Hotel::checkIn('205', 12);
    Hotel::checkIn('206', 12, guest: 'Ana Reyes');

    expect(fn () => ($this->transfer)($stay, '206'))->toThrow(RoomNotAvailable::class);

    expect(Approval::query()->count())->toBe(0)
        ->and($stay->fresh()->room_id)->toBe(Hotel::room('205')->id)
        ->and(StayRoom::query()->where('stay_id', $stay->id)->count())->toBe(1);
});

it('needs a note for a reason that asks for one', function (): void {
    $stay = Hotel::checkIn('205', 12);

    ($this->transfer)($stay, '206', reason: Hotel::transferReason('Other'));
})->throws(ActionRefused::class, 'A note is needed for Other.');
