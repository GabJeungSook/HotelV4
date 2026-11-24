<?php

declare(strict_types=1);

use App\Actions\Pos\CompleteSale;
use App\Actions\Pos\VoidSale;
use App\Enums\ApprovalAction;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PosMode;
use App\Enums\ReasonKind;
use App\Enums\StockMovementKind;
use App\Exceptions\ActionRefused;
use App\Exceptions\InsufficientStock;
use App\Exceptions\PinInvalid;
use App\Exceptions\ShiftStale;
use App\Models\Approval;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PosOrder;
use App\Models\StockMovement;
use App\Queries\StayQuery;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Tests\Support\Hotel;

/*
| POS (19): CompleteSale and VoidSale, APP_FLOW §4.13, TRD §10 ("add charge reduces stock;
| out-of-stock refused" for the POS form, idempotency, void with approval within the shift).
| Beer 330 ml ₱75 (36 in stock), Chips ₱35 (10), Coke 1.5 L ₱95 (0).
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00'); // 11:00 AM Manila, AM shift
    $this->shift = Hotel::openShift();
    $this->sell = fn (array $items, ?int $tendered = null, PosMode $mode = PosMode::WalkIn, mixed $stay = null, ?string $key = null) => app(CompleteSale::class)->handle(
        Hotel::frontdesk(), Hotel::branch()->id, $mode, $items, $key ?? (string) Str::uuid(),
        $tendered === null ? null : Money::ofCentavos($tendered), $stay,
    );
    $this->items = fn (array $named) => collect($named)->mapWithKeys(fn (int $qty, string $name) => [Hotel::product($name)->id => $qty])->all();
});

it('sells to a walk-in for cash: order, lines with no stay, stock out, one cash payment with change, all in the shift cash', function (): void {
    $before = Hotel::expectedCash();

    $order = ($this->sell)(($this->items)(['Beer 330 ml' => 2, 'Chips' => 1]), 20000);

    $payment = Payment::query()->where('pos_order_id', $order->id)->sole();
    $lines = FolioLine::query()->where('pos_order_id', $order->id)->orderBy('id')->get();
    expect([$order->mode, $order->stay_id, $order->total_amount->centavos(), $order->shift_session_id, $order->created_by])->toBe([PosMode::WalkIn, null, 18500, $this->shift->id, Hotel::frontdesk()->id])
        ->and($order->order_no)->toMatch('/^P-\d{6}$/')
        ->and($lines->map(fn (FolioLine $l) => [$l->stay_id, $l->kind, $l->description, $l->quantity, $l->amount->centavos(), $l->shift_session_id])->all())->toBe([
            [null, LineKind::FoodDrink, 'Chips', 1, 3500, $this->shift->id],
            [null, LineKind::FoodDrink, 'Beer 330 ml', 2, 15000, $this->shift->id],
        ])
        ->and([$payment->kind, $payment->method, $payment->stay_id, $payment->amount->centavos(), $payment->tendered_amount->centavos(), $payment->change_amount->centavos()])
        ->toBe([PaymentKind::Payment, PaymentMethod::Cash, null, 18500, 20000, 1500])
        ->and(PaymentAllocation::query()->where('payment_id', $payment->id)->sum('amount'))->toEqual(18500)
        ->and(Hotel::product('Beer 330 ml')->stock_on_hand)->toBe(34)
        ->and(Hotel::product('Chips')->stock_on_hand)->toBe(9)
        ->and(StockMovement::query()->where('kind', StockMovementKind::Sale)->pluck('balance_after', 'quantity')->all())->toEqualCanonicalizing([-2 => 34, -1 => 9])
        ->and(Hotel::expectedCash()->centavos())->toBe($before->centavos() + 18500);
});

it('must be paid in full and saves nothing when the cash is short', function (): void {
    expect(fn () => ($this->sell)(($this->items)(['Beer 330 ml' => 2]), 10000))->toThrow(ActionRefused::class, 'less than the amount to collect');

    expect(PosOrder::query()->count())->toBe(0)
        ->and(Hotel::product('Beer 330 ml')->stock_on_hand)->toBe(36);
});

it('refuses an item that is out of stock and rolls the whole sale back', function (): void {
    expect(fn () => ($this->sell)(($this->items)(['Chips' => 1, 'Coke 1.5 L' => 1]), 50000))->toThrow(InsufficientStock::class, 'Coke 1.5 L is out of stock');

    expect([PosOrder::query()->count(), FolioLine::query()->count() - FolioLine::query()->whereNotNull('stay_id')->count(), Payment::query()->whereNotNull('pos_order_id')->count()])->toBe([0, 0, 0])
        ->and(Hotel::product('Chips')->stock_on_hand)->toBe(10);
});

it('refuses more pieces than are on hand', function (): void {
    expect(fn () => ($this->sell)(($this->items)(['Chips' => 11]), 100000))->toThrow(InsufficientStock::class, 'Chips is out of stock (10 left)');
});

it('returns the first order for a repeated idempotency key and writes nothing the second time', function (): void {
    $key = (string) Str::uuid();
    $first = ($this->sell)(($this->items)(['Beer 330 ml' => 1]), 10000, key: $key);
    $again = ($this->sell)(($this->items)(['Beer 330 ml' => 1]), 10000, key: $key);

    expect($again->id)->toBe($first->id)
        ->and(PosOrder::query()->count())->toBe(1)
        ->and(Payment::query()->whereNotNull('pos_order_id')->count())->toBe(1)
        ->and(Hotel::product('Beer 330 ml')->stock_on_hand)->toBe(35);
});

it('charges to an occupied room: unpaid folio lines, stock out, no payment', function (): void {
    $stay = Hotel::checkIn('205');
    $payments = Payment::query()->count();

    $order = ($this->sell)(($this->items)(['Beer 330 ml' => 2]), mode: PosMode::RoomCharge, stay: $stay);

    $line = FolioLine::query()->where('pos_order_id', $order->id)->sole();
    expect([$order->mode, $order->stay_id, $line->stay_id, $line->room_id])->toBe([PosMode::RoomCharge, $stay->id, $stay->id, Hotel::room('205')->id])
        ->and(Payment::query()->count())->toBe($payments)
        ->and(app(StayQuery::class)->balanceDue($stay->id)->centavos())->toBe(15000)
        ->and(Hotel::product('Beer 330 ml')->stock_on_hand)->toBe(34);
});

it('refuses a room charge with no room picked', function (): void {
    expect(fn () => ($this->sell)(($this->items)(['Chips' => 1]), mode: PosMode::RoomCharge))->toThrow(ActionRefused::class, 'Pick the occupied room');
});

it('refuses a walk-in sale on a stale shift', function (): void {
    $this->travelClock(14 * 60); // 1:00 AM Manila: AM shift ended at 8:00 PM, more than 240 minutes ago

    expect(fn () => ($this->sell)(($this->items)(['Chips' => 1]), 5000))->toThrow(ShiftStale::class);
});

it('voids a walk-in sale with a supervisor PIN: approval, reversing lines, cash refund, stock back', function (): void {
    $order = ($this->sell)(($this->items)(['Beer 330 ml' => 2, 'Chips' => 1]), 20000);
    $before = Hotel::expectedCash();

    app(VoidSale::class)->handle(Hotel::frontdesk(), $order, Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount'), Hotel::supervisor(), Hotel::PIN, Hotel::device());

    $approval = Approval::query()->sole();
    $refund = Payment::query()->where('pos_order_id', $order->id)->where('kind', PaymentKind::Refund)->sole();
    $reversals = FolioLine::query()->where('pos_order_id', $order->id)->whereNotNull('reversal_of_id')->get();
    expect([$approval->action, $approval->subject_type, $approval->subject_id, $approval->approved_by])->toBe([ApprovalAction::VoidSale, 'pos_order', $order->id, Hotel::supervisor()->id])
        ->and($reversals)->toHaveCount(2)
        ->and($reversals->sum(fn (FolioLine $l) => $l->amount->centavos()))->toBe(-18500)
        ->and([$refund->amount->centavos(), $refund->method, $refund->approval_id])->toBe([-18500, PaymentMethod::Cash, $approval->id])
        ->and(PaymentAllocation::query()->where('payment_id', $refund->id)->sum('amount'))->toEqual(-18500)
        ->and(Hotel::product('Beer 330 ml')->stock_on_hand)->toBe(36)
        ->and(Hotel::product('Chips')->stock_on_hand)->toBe(10)
        ->and(StockMovement::query()->where('kind', StockMovementKind::SaleReversal)->count())->toBe(2)
        ->and(Hotel::expectedCash()->centavos())->toBe($before->centavos() - 18500);
});

it('voids a room-charge sale on the guest folio', function (): void {
    $stay = Hotel::checkIn('205');
    $order = ($this->sell)(($this->items)(['Beer 330 ml' => 2]), mode: PosMode::RoomCharge, stay: $stay);

    app(VoidSale::class)->handle(Hotel::frontdesk(), $order, Hotel::reason(ReasonKind::VoidLine, 'Charged to the wrong room'), Hotel::supervisor(), Hotel::PIN, Hotel::device());

    expect(app(StayQuery::class)->balanceDue($stay->id)->centavos())->toBe(0)
        ->and(Payment::query()->where('kind', PaymentKind::Refund)->count())->toBe(0)
        ->and(Hotel::product('Beer 330 ml')->stock_on_hand)->toBe(36);
});

it('refuses to void a sale twice', function (): void {
    $order = ($this->sell)(($this->items)(['Chips' => 1]), 5000);
    $void = fn () => app(VoidSale::class)->handle(Hotel::frontdesk(), $order, Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount'), Hotel::supervisor(), Hotel::PIN, Hotel::device());
    $void();

    expect($void)->toThrow(ActionRefused::class, 'already voided');
    expect(Approval::query()->count())->toBe(1);
});

it('refuses a wrong PIN and saves nothing of the void', function (): void {
    $order = ($this->sell)(($this->items)(['Chips' => 1]), 5000);

    expect(fn () => app(VoidSale::class)->handle(Hotel::frontdesk(), $order, Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount'), Hotel::supervisor(), '9999', Hotel::device()))
        ->toThrow(PinInvalid::class);

    expect(FolioLine::query()->whereNotNull('reversal_of_id')->count())->toBe(0)
        ->and(Hotel::product('Chips')->stock_on_hand)->toBe(9);
});

it('refuses a reason that needs a note without one', function (): void {
    $order = ($this->sell)(($this->items)(['Chips' => 1]), 5000);

    expect(fn () => app(VoidSale::class)->handle(Hotel::frontdesk(), $order, Hotel::reason(ReasonKind::VoidLine), Hotel::supervisor(), Hotel::PIN, Hotel::device()))
        ->toThrow(ActionRefused::class);
});

it('refuses to void a sale of an earlier shift; the old shift is unchanged', function (): void {
    $order = ($this->sell)(($this->items)(['Chips' => 1]), 5000);
    Hotel::closeAndHandOver(Hotel::user('rhea.bautista'));

    expect(fn () => app(VoidSale::class)->handle(Hotel::user('rhea.bautista'), $order, Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount'), Hotel::supervisor(), Hotel::PIN, Hotel::device()))
        ->toThrow(ActionRefused::class, 'earlier shift');

    expect(Hotel::product('Chips')->stock_on_hand)->toBe(9);
});

/*
| Meals (recipes): Tocilog ₱120 = 1 Rice + 1 Tocino + 1 Egg + 1 spoon + 1 plate. Selling a meal
| takes each ingredient out of its own stock; the meal keeps none. A void puts every one back.
*/

it('sells a meal: one folio line, every ingredient taken out, the meal keeps no stock of its own', function (): void {
    $order = ($this->sell)(($this->items)(['Tocilog' => 2]), 30000);
    $line = FolioLine::query()->where('pos_order_id', $order->id)->whereNull('reversal_of_id')->sole();

    expect($order->total_amount->centavos())->toBe(24000) // 2 × ₱120
        ->and($line->description)->toBe('Tocilog')
        ->and(Hotel::product('Rice')->stock_on_hand)->toBe(198)
        ->and(Hotel::product('Tocino')->stock_on_hand)->toBe(98)
        ->and(Hotel::product('Egg')->stock_on_hand)->toBe(198)
        ->and(Hotel::product('Plastic spoon & fork')->stock_on_hand)->toBe(298)
        ->and(Hotel::product('Meal plate')->stock_on_hand)->toBe(148)
        ->and(Hotel::product('Tocilog')->stock_on_hand)->toBe(0)
        // five ingredient movements, all pinned to the one meal line; the meal has none of its own
        ->and(StockMovement::query()->where('folio_line_id', $line->id)->where('kind', StockMovementKind::Sale)->count())->toBe(5)
        ->and(StockMovement::query()->where('product_id', Hotel::product('Tocilog')->id)->count())->toBe(0);
});

it('refuses a meal when an ingredient is short and rolls the whole sale back', function (): void {
    ($this->sell)(($this->items)(['Egg' => 199]), 300000); // leave a single egg

    expect(Hotel::product('Egg')->stock_on_hand)->toBe(1)
        ->and(fn () => ($this->sell)(($this->items)(['Tocilog' => 2]), 30000))->toThrow(InsufficientStock::class, 'Egg is out of stock (1 left)')
        ->and(Hotel::product('Rice')->stock_on_hand)->toBe(200)  // nothing deducted
        ->and(Hotel::product('Egg')->stock_on_hand)->toBe(1);
});

it('voids a meal sale: puts every ingredient back', function (): void {
    $order = ($this->sell)(($this->items)(['Tocilog' => 1]), 20000);
    expect(Hotel::product('Rice')->stock_on_hand)->toBe(199);

    app(VoidSale::class)->handle(Hotel::frontdesk(), $order, Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount'), Hotel::supervisor(), Hotel::PIN, Hotel::device());

    expect(Hotel::product('Rice')->stock_on_hand)->toBe(200)
        ->and(Hotel::product('Tocino')->stock_on_hand)->toBe(100)
        ->and(Hotel::product('Egg')->stock_on_hand)->toBe(200)
        ->and(Hotel::product('Plastic spoon & fork')->stock_on_hand)->toBe(300)
        ->and(Hotel::product('Meal plate')->stock_on_hand)->toBe(150)
        ->and(StockMovement::query()->where('kind', StockMovementKind::SaleReversal)->count())->toBe(5);
});
