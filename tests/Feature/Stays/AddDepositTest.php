<?php

declare(strict_types=1);

use App\Actions\Stays\AddCharges;
use App\Actions\Stays\AddDeposit;
use App\Actions\Stays\TakePayment;
use App\Enums\DepositType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Exceptions\ActionRefused;
use App\Models\Payment;
use App\Queries\StayQuery;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Tests\Support\Hotel;

/*
| Add deposit (9): the guest puts money on their account to spend on later charges and claim the
| rest at check-out (DATABASE_SCHEMA §8.4). It is a deposit_in of type guest — money in, not a
| charge — and never funded from a deposit.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    Hotel::openShift();
    $this->stay = Hotel::checkIn('205', 12); // fully paid at check-in, so the balance is zero
    $this->add = app(AddDeposit::class);
    $this->q = app(StayQuery::class);
});

it('adds a guest deposit without touching the balance', function (): void {
    expect($this->q->balanceDue($this->stay->id)->isZero())->toBeTrue();

    $payment = ($this->add)->handle(Hotel::frontdesk(), $this->stay, PaymentMethod::Cash, Money::ofPesos(1000), null, (string) Str::uuid());

    expect($payment->kind)->toBe(PaymentKind::DepositIn)
        ->and($payment->deposit_type)->toBe(DepositType::Guest)
        ->and($payment->amount->centavos())->toBe(100000)
        ->and($this->q->depositHeld($this->stay->id, DepositType::Guest)->centavos())->toBe(100000)
        ->and($this->q->balanceDue($this->stay->id)->isZero())->toBeTrue();
});

it('writes one deposit for a repeated idempotency key', function (): void {
    $key = (string) Str::uuid();

    $first = ($this->add)->handle(Hotel::frontdesk(), $this->stay, PaymentMethod::Cash, Money::ofPesos(500), null, $key);
    $again = ($this->add)->handle(Hotel::frontdesk(), $this->stay, PaymentMethod::Cash, Money::ofPesos(500), null, $key);

    expect($again->id)->toBe($first->id)
        ->and(Payment::query()->where('stay_id', $this->stay->id)->where('kind', PaymentKind::DepositIn)->where('deposit_type', DepositType::Guest)->count())->toBe(1)
        ->and($this->q->depositHeld($this->stay->id, DepositType::Guest)->centavos())->toBe(50000);
});

it('refuses a non-positive amount', function (): void {
    expect(fn () => ($this->add)->handle(Hotel::frontdesk(), $this->stay, PaymentMethod::Cash, Money::zero(), null, (string) Str::uuid()))
        ->toThrow(ActionRefused::class);
});

it('refuses to fund a deposit from a deposit', function (): void {
    expect(fn () => ($this->add)->handle(Hotel::frontdesk(), $this->stay, PaymentMethod::Deposit, Money::ofPesos(100), null, (string) Str::uuid()))
        ->toThrow(ActionRefused::class);
});

it('lets a later charge be paid from the deposit', function (): void {
    ($this->add)->handle(Hotel::frontdesk(), $this->stay, PaymentMethod::Cash, Money::ofPesos(1000), null, (string) Str::uuid());

    // A beer charged to the room leaves a balance; pay it from the guest deposit.
    $beer = Hotel::product('Beer 330 ml');
    app(AddCharges::class)->handle(Hotel::frontdesk(), $this->stay, [$beer->id => 1], (string) Str::uuid());
    $balance = $this->q->balanceDue($this->stay->id);
    expect($balance->isPositive())->toBeTrue();

    app(TakePayment::class)->handle(Hotel::frontdesk(), $this->stay, PaymentMethod::Deposit, $balance, idempotencyKey: (string) Str::uuid());

    expect($this->q->balanceDue($this->stay->id)->isZero())->toBeTrue()
        ->and($this->q->depositHeld($this->stay->id, DepositType::Guest)->centavos())->toBe(100000 - $balance->centavos());
});
