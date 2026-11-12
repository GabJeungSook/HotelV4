<?php

declare(strict_types=1);

use App\Enums\CashMovementKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\CashMovement;
use App\Models\Payment;
use App\Services\CashMath;
use App\Support\Money;

/*
| TRD §4.5. Rows are built in memory, never saved: CashMath reads them as values.
*/

beforeEach(function (): void {
    $this->math = new CashMath;
});

function payment(PaymentKind $kind, PaymentMethod $method, int $amount): Payment
{
    return new Payment(['kind' => $kind, 'method' => $method, 'amount' => $amount]);
}

function movement(CashMovementKind $kind, int $amount, bool $voided = false): CashMovement
{
    return new CashMovement(['kind' => $kind, 'amount' => $amount, 'voided_at' => $voided ? '2026-11-12 03:00:00' : null]);
}

it('works out expected cash from opening, cash payments and every movement kind', function (): void {
    $expected = $this->math->expectedCash(
        Money::ofCentavos(500000), Money::ofCentavos(1804500), Money::ofCentavos(65000), Money::ofCentavos(1000000), Money::ofCentavos(5000),
    );

    expect($expected->centavos())->toBe(500000 + 1804500 - 65000 - 1000000 + 5000);
});

it('counts only cash payments, signed, and leaves out voided movements', function (): void {
    $payments = [
        payment(PaymentKind::Payment, PaymentMethod::Cash, 65000),
        payment(PaymentKind::DepositIn, PaymentMethod::Cash, 20000),
        payment(PaymentKind::Payment, PaymentMethod::Gcash, 45000),
        payment(PaymentKind::DepositApplied, PaymentMethod::Deposit, 10000),
        payment(PaymentKind::DepositRefund, PaymentMethod::Cash, -20000),
        payment(PaymentKind::Refund, PaymentMethod::Cash, -9000),
    ];
    $movements = [
        movement(CashMovementKind::Expense, 15000),
        movement(CashMovementKind::Expense, 300000, voided: true),
        movement(CashMovementKind::Remittance, 50000),
        movement(CashMovementKind::ShortagePayment, 5000),
    ];

    $expected = $this->math->expectedFromRows(Money::ofCentavos(500000), $payments, $movements);

    expect($expected->centavos())->toBe(500000 + 65000 + 20000 - 20000 - 9000 - 15000 - 50000 + 5000);
});

it('gives a negative variance when the drawer is short', function (): void {
    expect($this->math->variance(Money::ofCentavos(95000), Money::ofCentavos(100000))->centavos())->toBe(-5000)
        ->and($this->math->variance(Money::ofCentavos(100000), Money::ofCentavos(100000))->isZero())->toBeTrue();
});

it('balances the three roll-forwards of the mockup shift report (37)', function (): void {
    expect($this->math->guestsForwardedOut(19, 27, 23))->toBe(23)
        ->and($this->math->depositsHeldClose(Money::ofCentavos(380000), Money::ofCentavos(540000), Money::ofCentavos(460000), Money::zero(), Money::zero())->centavos())->toBe(460000)
        ->and($this->math->unpaidForwardedOut(Money::ofCentavos(22500), Money::ofCentavos(1816000), Money::ofCentavos(1804500))->centavos())->toBe(34000);
});

it('checks the roll-forwards of a frozen totals array', function (): void {
    $totals = [
        'guests' => ['forwarded_in' => 19, 'checked_in' => 27, 'checked_out' => 23, 'forwarded_out' => 23],
        'deposits' => ['held_open' => 380000, 'taken' => 540000, 'returned' => 460000, 'forfeited' => 0, 'applied' => 0, 'held_close' => 460000],
        'unpaid' => ['forwarded_in' => 22500, 'charged' => 1816000, 'collected' => 1804500, 'forwarded_out' => 34000],
    ];

    expect($this->math->rollForwardsBalance($totals))->toBe(['guests' => true, 'deposits' => true, 'unpaid' => true]);

    $totals['unpaid']['forwarded_out'] = 34001;
    expect($this->math->rollForwardsBalance($totals)['unpaid'])->toBeFalse();
});
