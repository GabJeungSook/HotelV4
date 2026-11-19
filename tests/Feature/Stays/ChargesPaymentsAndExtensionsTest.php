<?php

declare(strict_types=1);

use App\Actions\Stays\AddCharges;
use App\Actions\Stays\ExtendStay;
use App\Actions\Stays\TakePayment;
use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PosMode;
use App\Enums\StockMovementKind;
use App\Exceptions\ActionRefused;
use App\Exceptions\InsufficientStock;
use App\Exceptions\RateNotFound;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\StockMovement;
use App\Queries\StayQuery;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\Support\Hotel;

/*
| AddCharges (10), TakePayment (11) and ExtendStay (12): DATABASE_SCHEMA §8.3–8.5,
| APP_FLOW §4.7–4.9 and their tests in TRD §10.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:12:00');
    $this->shift = Hotel::openShift();
    $this->stay = Hotel::checkIn('205', 12);
    $this->stays = app(StayQuery::class);
    $this->charge = fn (array $items, mixed ...$more) => app(AddCharges::class)->handle(Hotel::frontdesk(), $this->stay, $items, ...$more);
    $this->pay = fn (PaymentMethod $method, int $amount, mixed ...$more) => app(TakePayment::class)->handle(Hotel::frontdesk(), $this->stay, $method, Money::ofCentavos($amount), ...$more);
});

it('adds a charge to the folio and takes it out of stock', function (): void {
    $noodles = Hotel::product('Cup noodles');

    $order = ($this->charge)([$noodles->id => 2]);

    $line = FolioLine::query()->where('pos_order_id', $order->id)->sole();
    $movement = StockMovement::query()->where('folio_line_id', $line->id)->sole();
    expect([$order->mode, $order->stay_id, $order->total_amount->centavos(), $order->shift_session_id])->toBe([PosMode::RoomCharge, $this->stay->id, 9000, $this->shift->id])
        ->and($order->order_no)->toMatch('/^P-\d{6}$/')
        ->and([$line->kind, $line->quantity, $line->unit_amount->centavos(), $line->amount->centavos(), $line->room_id])
        ->toBe([LineKind::FoodDrink, 2, 4500, 9000, Hotel::room('205')->id])
        ->and([$movement->kind, $movement->quantity, $movement->balance_after])->toBe([StockMovementKind::Sale, -2, 28])
        ->and($noodles->fresh()->stock_on_hand)->toBe(28)
        ->and($this->stays->balanceDue($this->stay->id)->centavos())->toBe(9000);
});

it('refuses an item that is out of stock and saves nothing of the sale', function (): void {
    $water = Hotel::product('Bottled water 500 ml');
    $coke = Hotel::product('Coke 1.5 L');

    expect(fn () => ($this->charge)([$water->id => 1, $coke->id => 1]))
        ->toThrow(InsufficientStock::class, 'Coke 1.5 L is out of stock (0 left). Nothing was added.');

    expect(PosOrder::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0)
        ->and($water->fresh()->stock_on_hand)->toBe(36);
});

it('refuses more pieces than are on hand', function (): void {
    ($this->charge)([Hotel::product('Cup noodles')->id => 31]);
})->throws(InsufficientStock::class);

it('adds and takes the payment now in the same transaction', function (): void {
    ($this->charge)([Hotel::product('Bottled water 500 ml')->id => 1], payWith: PaymentMethod::Cash, amountGiven: Money::ofCentavos(5000));

    $payment = Payment::query()->where('stay_id', $this->stay->id)->latest('id')->first();
    expect([$payment->amount->centavos(), $payment->tendered_amount->centavos(), $payment->change_amount->centavos()])->toBe([2500, 5000, 2500])
        ->and($this->stays->balanceDue($this->stay->id)->isZero())->toBeTrue();
});

it('returns the first order for a repeated idempotency key', function (): void {
    $key = (string) Str::uuid();
    $chips = Hotel::product('Chips');

    $first = ($this->charge)([$chips->id => 1], $key);
    $again = ($this->charge)([$chips->id => 1], $key);

    expect($again->id)->toBe($first->id)->and(PosOrder::query()->count())->toBe(1)->and($chips->fresh()->stock_on_hand)->toBe(9);
});

it('pays the oldest line first when the payment is smaller than the balance', function (): void {
    $older = ($this->charge)([Hotel::product('Cup noodles')->id => 2]);
    $this->travelClock(10);
    $newer = ($this->charge)([Hotel::product('Bottled water 500 ml')->id => 1]);

    ($this->pay)(PaymentMethod::Cash, 9000);

    $statuses = collect($this->stays->folio($this->stay->id)['lines'])->pluck('status', 'description')->all();
    expect($statuses)->toBe(['Deluxe 12 hrs' => 'paid', 'Cup noodles' => 'paid', 'Bottled water 500 ml' => 'unpaid'])
        ->and($this->stays->balanceDue($this->stay->id)->centavos())->toBe(2500)
        ->and(FolioLine::query()->where('pos_order_id', $newer->id)->withSum('allocations', 'amount')->sole()->allocations_sum_amount)->toBeNull()
        ->and($older->id)->toBeLessThan($newer->id);
});

it('splits a payment across lines oldest first and stops where the money runs out', function (): void {
    ($this->charge)([Hotel::product('Cup noodles')->id => 2]);
    $this->travelClock(1);
    ($this->charge)([Hotel::product('Bottled water 500 ml')->id => 2]);

    ($this->pay)(PaymentMethod::Cash, 11000);

    $paid = collect($this->stays->folio($this->stay->id)['lines'])->mapWithKeys(fn (array $l): array => [$l['description'] => $l['paid']->centavos()])->all();
    expect($paid)->toBe(['Deluxe 12 hrs' => 65000, 'Cup noodles' => 9000, 'Bottled water 500 ml' => 2000]);
});

it('gives change for cash above the balance, or keeps it as a guest deposit', function (): void {
    ($this->charge)([Hotel::product('Chips')->id => 1]);
    $payment = ($this->pay)(PaymentMethod::Cash, 10000);
    expect([$payment->amount->centavos(), $payment->change_amount->centavos()])->toBe([3500, 6500]);

    ($this->charge)([Hotel::product('Chips')->id => 1]);
    ($this->pay)(PaymentMethod::Cash, 10000, keepChangeAsDeposit: true);
    expect($this->stays->depositHeld($this->stay->id, DepositType::Guest)->centavos())->toBe(6500);
});

it('refuses a GCash payment above the balance', function (): void {
    ($this->charge)([Hotel::product('Chips')->id => 1]);

    ($this->pay)(PaymentMethod::Gcash, 10000, '5551234');
})->throws(ActionRefused::class, 'cannot be more than the balance due');

it('pays from the guest deposit, refuses more than is held, and never uses the key deposit', function (): void {
    $stay = Hotel::checkIn('206', 12, tendered: 100000, keepChange: true, guest: 'Ana Reyes'); // ₱150 guest deposit
    app(AddCharges::class)->handle(Hotel::frontdesk(), $stay, [Hotel::product('Cup noodles')->id => 4]); // ₱180
    $pay = fn (int $amount, DepositType $from = DepositType::Guest) => app(TakePayment::class)->handle(Hotel::frontdesk(), $stay, PaymentMethod::Deposit, Money::ofCentavos($amount), fromDeposit: $from);

    expect(fn () => $pay(15001))->toThrow(ActionRefused::class, 'more than the guest deposit held')
        ->and(fn () => $pay(1000, DepositType::Key))->toThrow(ActionRefused::class, 'The key deposit can only be used at check-out.');

    $applied = $pay(15000);

    expect([$applied->kind, $applied->method, $applied->deposit_type, $applied->amount->centavos()])->toBe([PaymentKind::DepositApplied, PaymentMethod::Deposit, DepositType::Guest, 15000])
        ->and($this->stays->depositHeld($stay->id, DepositType::Guest)->isZero())->toBeTrue()
        ->and($this->stays->depositHeld($stay->id, DepositType::Key)->centavos())->toBe(20000)
        ->and($this->stays->balanceDue($stay->id)->centavos())->toBe(3000);
});

it('returns the first payment for a repeated idempotency key and writes nothing the second time', function (): void {
    ($this->charge)([Hotel::product('Cup noodles')->id => 2]);
    $key = (string) Str::uuid();

    $first = ($this->pay)(PaymentMethod::Cash, 5000, idempotencyKey: $key);
    $count = Payment::query()->count();
    $second = ($this->pay)(PaymentMethod::Cash, 5000, idempotencyKey: $key);

    expect($second->id)->toBe($first->id)
        ->and($first->idempotency_key)->toBe($key)
        ->and(Payment::query()->count())->toBe($count)
        ->and($this->stays->balanceDue($this->stay->id)->centavos())->toBe(4000);
});

it('refuses a second payment row with the same idempotency key in the database', function (): void {
    ($this->charge)([Hotel::product('Cup noodles')->id => 2]);
    $key = (string) Str::uuid();
    $first = ($this->pay)(PaymentMethod::Cash, 5000, idempotencyKey: $key);

    expect(fn () => Payment::query()->create([...$first->only(['branch_id', 'shift_session_id', 'stay_id', 'kind', 'method', 'amount', 'created_by', 'occurred_at', 'created_at']), 'idempotency_key' => $key]))
        ->toThrow(QueryException::class, 'payments_idempotency_unique');
});

it('refuses a payment when nothing is due', function (): void {
    ($this->pay)(PaymentMethod::Cash, 1000);
})->throws(ActionRefused::class, 'There is nothing to pay on this folio.');

it('extends within the cycle: extension line, later check-out, more cycle hours', function (): void {
    $line = app(ExtendStay::class)->handle(Hotel::frontdesk(), $this->stay, 6);

    $stay = $this->stay->fresh();
    expect([$line->kind, $line->description, $line->amount->centavos(), $line->shift_session_id])->toBe([LineKind::Extension, 'Extension 6 hrs', 25000, $this->shift->id])
        ->and($stay->expected_checkout_at->toDateTimeString())->toBe('2026-11-12 19:12:00')
        ->and($stay->cycle_hours_used)->toBe(18)
        ->and($this->stays->balanceDue($stay->id)->centavos())->toBe(25000);
});

it('extends across the cycle end and takes the payment now', function (): void {
    $line = app(ExtendStay::class)->handle(Hotel::frontdesk(), $this->stay, 24, PaymentMethod::Cash, Money::ofCentavos(110000));

    $stay = $this->stay->fresh();
    expect($line->amount->centavos())->toBe(105000)
        ->and($stay->cycle_hours_used)->toBe(12)
        ->and($stay->expected_checkout_at->toDateTimeString())->toBe('2026-11-13 13:12:00')
        ->and($this->stays->balanceDue($stay->id)->isZero())->toBeTrue()
        ->and(Payment::query()->where('stay_id', $stay->id)->latest('id')->first()->change_amount->centavos())->toBe(5000);
});

it('returns the first extension for a repeated idempotency key and charges once', function (): void {
    $key = (string) Str::uuid();

    $first = app(ExtendStay::class)->handle(Hotel::frontdesk(), $this->stay, 6, PaymentMethod::Cash, Money::ofCentavos(25000), idempotencyKey: $key);
    $payments = Payment::query()->count();
    $second = app(ExtendStay::class)->handle(Hotel::frontdesk(), $this->stay, 6, PaymentMethod::Cash, Money::ofCentavos(25000), idempotencyKey: $key);

    $stay = $this->stay->fresh();
    expect($second->id)->toBe($first->id)
        ->and($first->idempotency_key)->toBe($key)
        ->and(FolioLine::query()->where('kind', LineKind::Extension)->count())->toBe(1)
        ->and(Payment::query()->count())->toBe($payments)
        // The check-out moved by 6 hours once, not twice.
        ->and($stay->expected_checkout_at->toDateTimeString())->toBe('2026-11-12 19:12:00')
        ->and($stay->cycle_hours_used)->toBe(18);
});

it('refuses an extension without a rate and changes nothing', function (): void {
    expect(fn () => app(ExtendStay::class)->handle(Hotel::frontdesk(), $this->stay, 2))->toThrow(RateNotFound::class);

    expect($this->stay->fresh()->expected_checkout_at->toDateTimeString())->toBe('2026-11-12 13:12:00')
        ->and(FolioLine::query()->where('kind', LineKind::Extension)->count())->toBe(0);
});
