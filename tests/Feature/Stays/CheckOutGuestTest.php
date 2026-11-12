<?php

declare(strict_types=1);

use App\Actions\Stays\AddCharges;
use App\Actions\Stays\CheckOutGuest;
use App\Enums\CleaningTrigger;
use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\RoomStatus;
use App\Enums\StayState;
use App\Exceptions\BalanceNotZero;
use App\Models\CleaningTask;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\StayRoom;
use App\Queries\StayQuery;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\Hotel;

/*
| CheckOutGuest (16–18): DATABASE_SCHEMA §8.8, APP_FLOW §4.12. Key deposit ₱200, lost key ₱100,
| lost remote ₱100.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:12:00');
    $this->shift = Hotel::openShift();
    $this->stay = Hotel::checkIn('205', 12);
    $this->stays = app(StayQuery::class);
    $this->checkOut = fn (mixed ...$args) => app(CheckOutGuest::class)->handle(Hotel::frontdesk(), $this->stay, ...$args);
    $this->travelClock(11 * 60);
});

it('checks out: hands back the key deposit, closes the stay, room to uncleaned with a cleaning task', function (): void {
    $stay = ($this->checkOut)();

    $refund = Payment::query()->where('kind', PaymentKind::DepositRefund)->sole();
    $task = CleaningTask::query()->sole();
    expect($stay->state)->toBe(StayState::CheckedOut)
        ->and($stay->checked_out_at->toDateTimeString())->toBe('2026-11-12 12:12:00')
        ->and([$stay->checkout_shift_id, $stay->checked_out_by])->toBe([$this->shift->id, Hotel::frontdesk()->id])
        ->and([$refund->amount->centavos(), $refund->method, $refund->deposit_type])->toBe([-20000, PaymentMethod::Cash, DepositType::Key])
        ->and(Hotel::room('205')->status)->toBe(RoomStatus::Uncleaned)
        ->and(Hotel::room('205')->last_checkout_at->toDateTimeString())->toBe('2026-11-12 12:12:00')
        ->and(StayRoom::query()->where('stay_id', $stay->id)->sole()->to_at->toDateTimeString())->toBe('2026-11-12 12:12:00')
        ->and([$task->stay_id, $task->trigger_kind, $task->due_at->toDateTimeString()])->toBe([$stay->id, CleaningTrigger::CheckOut, '2026-11-12 16:12:00']);
});

it('is blocked while a balance remains, and saves nothing', function (): void {
    app(AddCharges::class)->handle(Hotel::frontdesk(), $this->stay, [Hotel::product('Cup noodles')->id => 1]);
    $lines = FolioLine::query()->count();

    expect(fn () => ($this->checkOut)(keyReturned: false))->toThrow(BalanceNotZero::class);

    expect($this->stay->fresh()->state)->toBe(StayState::CheckedIn)
        ->and(FolioLine::query()->count())->toBe($lines)
        ->and(Hotel::room('205')->status)->toBe(RoomStatus::Occupied);
});

it('collects the balance as part of the check-out', function (): void {
    app(AddCharges::class)->handle(Hotel::frontdesk(), $this->stay, [Hotel::product('Cup noodles')->id => 1]);

    $stay = ($this->checkOut)(payWith: PaymentMethod::Cash, amountGiven: Money::ofCentavos(5000));

    expect($stay->state)->toBe(StayState::CheckedOut)
        ->and(Payment::query()->where('kind', PaymentKind::Payment)->latest('id')->first()->change_amount->centavos())->toBe(500);
});

it('takes a lost key out of the key deposit and hands back the rest', function (): void {
    ($this->checkOut)(keyReturned: false);

    $damage = FolioLine::query()->where('kind', LineKind::Damage)->sole();
    $applied = Payment::query()->where('kind', PaymentKind::DepositApplied)->sole();

    expect([$damage->description, $damage->amount->centavos(), $damage->product_id])->toBe(['Lost key', 10000, null])
        ->and([$applied->deposit_type, $applied->amount->centavos(), $applied->allocations()->sole()->folio_line_id])->toBe([DepositType::Key, 10000, $damage->id])
        ->and(Payment::query()->where('kind', PaymentKind::DepositRefund)->sole()->amount->centavos())->toBe(-10000)
        ->and($this->stays->depositHeld($this->stay->id, DepositType::Key)->isZero())->toBeTrue();
});

it('charges damage products and covers them from the deposit', function (): void {
    ($this->checkOut)(remoteReturned: false, damages: [Hotel::product('Broken glass')->id => 1]);

    expect(FolioLine::query()->where('kind', LineKind::Damage)->orderBy('id')->pluck('description')->all())->toBe(['Lost remote', 'Broken glass'])
        ->and(Payment::query()->where('kind', PaymentKind::DepositRefund)->sole()->amount->centavos())->toBe(-5000);
});

it('needs a payment when damages are more than the deposit', function (): void {
    expect(fn () => ($this->checkOut)(keyReturned: false, remoteReturned: false, damages: [Hotel::product('Stained linen')->id => 1]))
        ->toThrow(BalanceNotZero::class);

    $stay = ($this->checkOut)(keyReturned: false, remoteReturned: false, damages: [Hotel::product('Stained linen')->id => 1], payWith: PaymentMethod::Cash, amountGiven: Money::ofCentavos(15000));

    expect($stay->state)->toBe(StayState::CheckedOut)
        ->and(Payment::query()->where('kind', PaymentKind::DepositRefund)->count())->toBe(0);
});

it('keeps an unclaimed deposit as income with a forfeited_deposit line', function (): void {
    ($this->checkOut)(depositClaimed: false);

    $line = FolioLine::query()->where('kind', LineKind::ForfeitedDeposit)->sole();
    $applied = Payment::query()->where('kind', PaymentKind::DepositApplied)->sole();

    expect([$line->amount->centavos(), $line->description, $line->shift_session_id])->toBe([20000, 'Unclaimed Key & remote deposit', $this->shift->id])
        ->and([$applied->deposit_type, $applied->amount->centavos()])->toBe([DepositType::Key, 20000])
        ->and(Payment::query()->where('kind', PaymentKind::DepositRefund)->count())->toBe(0)
        ->and($this->stays->depositHeld($this->stay->id, DepositType::Key)->isZero())->toBeTrue()
        ->and($this->stays->balanceDue($this->stay->id)->isZero())->toBeTrue();
});

it('does nothing on a second check-out', function (): void {
    $first = ($this->checkOut)();
    $payments = Payment::query()->count();

    $again = ($this->checkOut)(keyReturned: false);

    expect($again->id)->toBe($first->id)
        ->and($again->state)->toBe(StayState::CheckedOut)
        ->and(Payment::query()->count())->toBe($payments)
        ->and(FolioLine::query()->where('kind', LineKind::Damage)->count())->toBe(0)
        ->and(CleaningTask::query()->count())->toBe(1);
});

it('sends the room straight to available with housekeeping switched off', function (): void {
    Hotel::branch()->update(['housekeeping_enabled' => false]);

    ($this->checkOut)();

    expect(Hotel::room('205')->status)->toBe(RoomStatus::Available)->and(CleaningTask::query()->count())->toBe(0);
});
