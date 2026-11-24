<?php

declare(strict_types=1);

use App\Actions\Pos\CompleteSale;
use App\Actions\Pos\VoidSale;
use App\Actions\Stays\AddCharges;
use App\Actions\Stays\CheckOutGuest;
use App\Actions\Stays\ExtendStay;
use App\Actions\Stays\TransferRoom;
use App\Enums\CashMovementKind;
use App\Enums\ExtensionRule;
use App\Enums\PaymentMethod;
use App\Enums\PosMode;
use App\Enums\ReasonKind;
use App\Enums\RoomStatus;
use App\Enums\StayKind;
use App\Models\CashMovement;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Room;
use App\Services\CashMath;
use App\Services\ReportService;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Tests\Support\Hotel;

/*
| A broad front-desk audit: one shift driven through every money flow the front desk touches —
| every room rate (hourly, overnight, long stay), every extension rule (cycle, per-hour, block),
| a transfer up and down, POS (walk-in, room charge, a meal), an amenity charge settled at
| check-out, cash in and out (expense, remittance), a void, and check-outs — then the closed
| shift must reconcile: frozen totals equal an independent ledger recompute, ReportService
| reproduces them, cash ties out, and the TRD §8 invariants hold (checked afterEach).
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00'); // 11 AM Manila, AM shift
    $this->branch = Hotel::branch();
    $this->jessa = Hotel::frontdesk();
    $this->sup = Hotel::supervisor();
    $this->mark = Hotel::user('mark.sumagaysay');

    $this->rooms = fn (string $type): array => Room::query()->where('branch_id', $this->branch->id)
        ->whereHas('roomType', fn ($q) => $q->where('name', $type))
        ->where('status', RoomStatus::Available)->where('is_active', true)
        ->orderBy('id')->pluck('number')->values()->all();

    $this->expectedFromRows = function (int $shiftId, int $opening): int {
        $cash = (int) Payment::query()->where('shift_session_id', $shiftId)->where('method', 'cash')->sum('amount');
        $kind = fn (string $k): int => (int) CashMovement::query()->where('shift_session_id', $shiftId)->where('kind', $k)->whereNull('voided_at')->sum('amount');

        return $opening + $cash - $kind('expense') - $kind('remittance') + $kind('shortage_payment');
    };
});

it('reconciles a shift that used every front-desk flow', function (): void {
    $std = ($this->rooms)('Standard');
    $dlx = ($this->rooms)('Deluxe');
    $suite = ($this->rooms)('Suite');
    $device = Hotel::device();
    $amenity = Product::query()->where('branch_id', $this->branch->id)->where('kind', 'amenity')->where('is_active', true)->firstOrFail();

    $shift = Hotel::openShift($this->jessa, 500000);

    // --- Room rates: hourly, overnight, long stay ---
    $h = Hotel::checkIn($dlx[0], 12, StayKind::Hourly);        // Deluxe 12 h
    $o = Hotel::checkIn($std[0], 1, StayKind::Overnight);      // Standard overnight
    $l = Hotel::checkIn($suite[0], 2, StayKind::LongStay);     // Suite long stay, 2 days
    $hc = Hotel::checkIn($dlx[1], 12, StayKind::Hourly);       // for a per-hour extension
    $up = Hotel::checkIn($dlx[2], 12, StayKind::Hourly);       // for a transfer up
    $down = Hotel::checkIn($dlx[3], 12, StayKind::Hourly);     // for a transfer down

    // --- Extensions: cycle, per-hour, block (overnight extends by block regardless) ---
    $this->branch->update(['extension_rule' => ExtensionRule::Cycle]);
    app(ExtendStay::class)->handle($this->jessa, $h->refresh(), 6, PaymentMethod::Cash, Money::ofCentavos(60000), idempotencyKey: (string) Str::uuid());

    $this->branch->update(['extension_rule' => ExtensionRule::PerHour, 'extension_per_hour_amount' => Money::ofCentavos(8000)]);
    app(ExtendStay::class)->handle($this->jessa, $hc->refresh(), 3, PaymentMethod::Cash, Money::ofCentavos(60000), idempotencyKey: (string) Str::uuid());

    app(ExtendStay::class)->handle($this->jessa, $o->refresh(), 6, PaymentMethod::Cash, Money::ofCentavos(60000), idempotencyKey: (string) Str::uuid());

    // --- Transfers: up (collect the difference) and down (a deposit credit) ---
    app(TransferRoom::class)->handle($this->jessa, $up->refresh(), Hotel::room($suite[1]), Hotel::transferReason(), $this->sup, Hotel::PIN, $device, payWith: PaymentMethod::Cash, amountGiven: Money::ofCentavos(100000));
    app(TransferRoom::class)->handle($this->jessa, $down->refresh(), Hotel::room($std[1]), Hotel::transferReason(), $this->sup, Hotel::PIN, $device);

    // --- POS: a walk-in sale, a meal, a room charge ---
    $sale = app(CompleteSale::class)->handle($this->jessa, $this->branch->id, PosMode::WalkIn, [Hotel::product('Beer 330 ml')->id => 2, Hotel::product('Chips')->id => 1], (string) Str::uuid(), Money::ofCentavos(50000));
    app(CompleteSale::class)->handle($this->jessa, $this->branch->id, PosMode::WalkIn, [Hotel::product('Tocilog')->id => 1], (string) Str::uuid(), Money::ofCentavos(20000));
    app(AddCharges::class)->handle($this->jessa, $o->refresh(), [Hotel::product('Coke in can')->id => 2], (string) Str::uuid(), PaymentMethod::Cash, Money::ofCentavos(20000));

    // --- An amenity charged to a stay, left unpaid and settled at check-out ---
    app(AddCharges::class)->handle($this->jessa, $l->refresh(), [$amenity->id => 1], (string) Str::uuid());

    // --- Cash in and out ---
    Hotel::movement(CashMovementKind::Expense, 15000);
    Hotel::movement(CashMovementKind::Remittance, 100000);

    // --- Void the walk-in sale ---
    app(VoidSale::class)->handle($this->jessa, $sale, Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount'), $this->sup, Hotel::PIN, $device);

    // --- Check-outs: one paid up, one settling the amenity balance ---
    app(CheckOutGuest::class)->handle($this->jessa, $o->refresh());
    app(CheckOutGuest::class)->handle($this->jessa, $l->refresh(), payWith: PaymentMethod::Cash, amountGiven: Money::ofCentavos(500000));

    // --- Close and reconcile ---
    Hotel::closeAndHandOver($this->mark);
    $shift->refresh();

    $salesFromLedger = (int) FolioLine::query()->where('shift_session_id', $shift->id)->sum('amount');
    $expectedFromLedger = ($this->expectedFromRows)($shift->id, $shift->opening_cash->centavos());

    expect($shift->totals['sales']['total'])->toBe($salesFromLedger)
        ->and($shift->expected_cash->centavos())->toBe($expectedFromLedger)
        ->and($shift->variance_amount->centavos())->toBe($shift->counted_cash->centavos() - $shift->expected_cash->centavos())
        ->and(app(CashMath::class)->rollForwardsBalance($shift->totals))->toBe(['guests' => true, 'deposits' => true, 'unpaid' => true]);

    $again = app(ReportService::class)->recompute($shift);
    expect($again['sales'])->toEqual($shift->totals['sales'])
        ->and($again['collected'])->toEqual($shift->totals['collected'])
        ->and($again['deposits'])->toEqual($shift->totals['deposits'])
        ->and($again['unpaid'])->toEqual($shift->totals['unpaid'])
        ->and($again['guests'])->toEqual($shift->totals['guests']);

    expect($shift->grand_total_after->centavos())->toBe($shift->grand_total_before->centavos() + $shift->totals['sales']['total']);
});
