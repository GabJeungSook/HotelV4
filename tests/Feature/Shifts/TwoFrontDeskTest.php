<?php

declare(strict_types=1);

use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\JoinShift;
use App\Actions\Stays\AddCharges;
use App\Actions\Stays\AddDeposit;
use App\Actions\Stays\CheckOutGuest;
use App\Actions\Stays\TakePayment;
use App\Enums\DepositType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Exceptions\ShiftClosing;
use App\Models\Payment;
use App\Models\Product;
use App\Queries\StayQuery;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\Hotel;

/*
| Two front desk on one shift — the scenarios most likely to go wrong when two people share a drawer:
| who each peso is attributed to, double-submits, the close pausing the other person's writes, and a
| deposit one took being spent by the other. Rules: money is append-only, shift-bound, and one room
| has one open stay (CLAUDE.md 3–5, 10).
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    $this->branch = Hotel::branch();
    $this->jessa = Hotel::frontdesk();
    $this->rhea = Hotel::user('rhea.bautista');
    $this->shift = Hotel::openShift($this->jessa);             // Jessa opens
    app(JoinShift::class)->handle($this->rhea, $this->branch->id); // Rhea joins the same shift
});

it('attributes each member\'s work to that member, all on the one open shift', function (): void {
    $a = Hotel::checkIn('205', 12, by: $this->jessa);
    $b = Hotel::checkIn('206', 12, by: $this->rhea);

    expect($a->checked_in_by)->toBe($this->jessa->id)
        ->and($b->checked_in_by)->toBe($this->rhea->id);

    // Every payment either member made is bound to the single open shift.
    $shiftIds = Payment::query()->whereIn('created_by', [$this->jessa->id, $this->rhea->id])->pluck('shift_session_id')->unique();
    expect($shiftIds->all())->toBe([$this->shift->id]);
});

it('takes a payment once when the same submit arrives twice (double-click / two tabs)', function (): void {
    $stay = Hotel::checkIn('205', 12, by: $this->jessa);
    $product = Product::query()->where('branch_id', $this->branch->id)->firstOrFail();
    app(AddCharges::class)->handle($this->jessa, $stay, [$product->id => 2]); // an unpaid charge
    $due = app(StayQuery::class)->balanceDue($stay->id);
    expect($due->isPositive())->toBeTrue();

    $key = 'two-tabs-1';
    $first = app(TakePayment::class)->handle($this->jessa, $stay, PaymentMethod::Cash, $due, idempotencyKey: $key);
    $second = app(TakePayment::class)->handle($this->rhea, $stay, PaymentMethod::Cash, $due, idempotencyKey: $key);

    expect($second->id)->toBe($first->id)                                   // same row, not a second charge
        ->and(app(StayQuery::class)->balanceDue($stay->id)->isZero())->toBeTrue()
        ->and(Payment::query()->where('idempotency_key', $key)->count())->toBe(1);
});

it('pauses the other member\'s payment, deposit, charge and checkout once the close starts', function (): void {
    $stay = Hotel::checkIn('205', 12, by: $this->jessa);
    $product = Product::query()->where('branch_id', $this->branch->id)->firstOrFail();

    app(CloseShift::class)->begin($this->jessa, $this->branch->id); // Jessa starts the close

    // Rhea is still signed on and tries to keep working — every money write is refused by name.
    expect(fn () => app(TakePayment::class)->handle($this->rhea, $stay, PaymentMethod::Cash, Money::ofCentavos(10000)))
        ->toThrow(ShiftClosing::class);
    expect(fn () => app(AddDeposit::class)->handle($this->rhea, $stay, PaymentMethod::Cash, Money::ofCentavos(10000)))
        ->toThrow(ShiftClosing::class);
    expect(fn () => app(AddCharges::class)->handle($this->rhea, $stay, [$product->id => 1]))
        ->toThrow(ShiftClosing::class);
    expect(fn () => app(CheckOutGuest::class)->handle($this->rhea, $stay))
        ->toThrow(ShiftClosing::class);
});

it('lets one member spend a deposit the other member took', function (): void {
    $stay = Hotel::checkIn('205', 12, by: $this->jessa);
    app(AddDeposit::class)->handle($this->jessa, $stay, PaymentMethod::Cash, Money::ofCentavos(100000)); // Jessa: ₱1,000 deposit

    $product = Product::query()->where('branch_id', $this->branch->id)->firstOrFail();
    app(AddCharges::class)->handle($this->rhea, $stay, [$product->id => 1]); // Rhea posts a charge
    $due = app(StayQuery::class)->balanceDue($stay->id);

    // Rhea settles it out of Jessa's deposit.
    app(TakePayment::class)->handle($this->rhea, $stay, PaymentMethod::Deposit, $due, fromDeposit: DepositType::Guest);

    expect(app(StayQuery::class)->balanceDue($stay->id)->isZero())->toBeTrue()
        ->and(app(StayQuery::class)->depositHeld($stay->id, DepositType::Guest)->centavos())->toBe(100000 - $due->centavos())
        ->and(Payment::query()->where('stay_id', $stay->id)->where('kind', PaymentKind::DepositApplied)->exists())->toBeTrue();
});

it('does not refund the deposit twice when a stay is checked out twice', function (): void {
    $stay = Hotel::checkIn('205', 12, by: $this->jessa); // ₱200 key deposit held

    $first = app(CheckOutGuest::class)->handle($this->jessa, $stay, keyReturned: true);
    $refundsAfterFirst = Payment::query()->where('stay_id', $stay->id)->where('kind', PaymentKind::DepositRefund)->count();

    // Rhea clicks checkout on a stale screen — the stay is already out, so it is a no-op.
    $second = app(CheckOutGuest::class)->handle($this->rhea, $stay, keyReturned: true);
    $refundsAfterSecond = Payment::query()->where('stay_id', $stay->id)->where('kind', PaymentKind::DepositRefund)->count();

    expect($second->id)->toBe($first->id)
        ->and($refundsAfterSecond)->toBe($refundsAfterFirst); // no second refund
});
