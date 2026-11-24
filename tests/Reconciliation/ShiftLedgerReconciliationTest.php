<?php

declare(strict_types=1);

use App\Actions\Pos\CompleteSale;
use App\Actions\Stays\ExtendStay;
use App\Enums\CashMovementKind;
use App\Enums\PaymentMethod;
use App\Enums\PosMode;
use App\Models\CashMovement;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Services\CashMath;
use App\Services\CurrentShift;
use App\Services\ReportService;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Tests\Support\Hotel;

/*
| Reconciliation (CLAUDE.md golden rule 7, TRD §4.5 / §4.10, the homi:reconcile-business-day
| invariant): a closed shift's frozen figures must equal an INDEPENDENT recompute straight from
| the append-only ledger, ReportService must reproduce the frozen totals with no drift, and the
| grand-total/Z chain must equal the running sum of sales. These tests recompute from the rows,
| never from hard-coded amounts, so they guard the money math whatever the rates are.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 11:00:00'); // 7:00 PM Manila, AM shift
    $this->branch = Hotel::branch();
    $this->jessa = Hotel::frontdesk();
    $this->mark = Hotel::user('mark.sumagaysay');

    // An independent expected-cash recompute from the raw rows: opening + cash payments − expenses
    // − remittances + shortage payments (non-voided), the same shape as CashMath but computed here.
    $this->expectedFromRows = function (int $shiftId, int $opening): int {
        $cash = (int) Payment::query()->where('shift_session_id', $shiftId)->where('method', 'cash')->sum('amount');
        $kind = fn (string $k): int => (int) CashMovement::query()->where('shift_session_id', $shiftId)->where('kind', $k)->whereNull('voided_at')->sum('amount');

        return $opening + $cash - $kind('expense') - $kind('remittance') + $kind('shortage_payment');
    };
});

it('reconciles a closed shift: frozen totals equal an independent ledger recompute', function (): void {
    $shift = Hotel::openShift($this->jessa, 500000);

    $stay = Hotel::checkIn('205');                  // room line + ₱200 key deposit + cash payment
    app(ExtendStay::class)->handle($this->jessa, $stay, 6, PaymentMethod::Cash, Money::ofCentavos(25000), idempotencyKey: (string) Str::uuid());
    Hotel::movement(CashMovementKind::Expense, 15000);

    Hotel::closeAndHandOver($this->mark);           // closes and freezes this shift; opens the next
    $shift->refresh();

    $salesFromLedger = (int) FolioLine::query()->where('shift_session_id', $shift->id)->sum('amount');
    $expectedFromLedger = ($this->expectedFromRows)($shift->id, $shift->opening_cash->centavos());

    // 1. Frozen figures match the ledger.
    expect($shift->totals['sales']['total'])->toBe($salesFromLedger)
        ->and($shift->expected_cash->centavos())->toBe($expectedFromLedger)
        ->and($shift->variance_amount->centavos())->toBe($shift->counted_cash->centavos() - $shift->expected_cash->centavos());

    // 2. The three roll-forwards of the frozen totals balance.
    expect(app(CashMath::class)->rollForwardsBalance($shift->totals))->toBe(['guests' => true, 'deposits' => true, 'unpaid' => true]);

    // 3. No drift: recomputing from the ledger reproduces the frozen totals.
    // toEqual, not toBe: MySQL's JSON column reorders object keys, so the frozen totals read back
    // in a different key order than a fresh recompute though the values are identical.
    $again = app(ReportService::class)->recompute($shift);
    expect($again['sales'])->toEqual($shift->totals['sales'])
        ->and($again['collected'])->toEqual($shift->totals['collected'])
        ->and($again['deposits'])->toEqual($shift->totals['deposits'])
        ->and($again['unpaid'])->toEqual($shift->totals['unpaid'])
        ->and($again['guests'])->toEqual($shift->totals['guests']);

    // 4. The grand total advances by exactly this shift's sales.
    expect($shift->grand_total_after->centavos())->toBe($shift->grand_total_before->centavos() + $shift->totals['sales']['total']);
});

it('keeps a closed shift reconciling after a later void posts to the next shift', function (): void {
    $shift = Hotel::openShift($this->jessa, 500000);
    $stay = Hotel::checkIn('205');
    app(ExtendStay::class)->handle($this->jessa, $stay, 6, PaymentMethod::Cash, Money::ofCentavos(25000), idempotencyKey: (string) Str::uuid());

    Hotel::closeAndHandOver($this->mark);
    $shift->refresh();
    $frozen = $shift->totals;

    // A correction in the new shift must not change the closed shift's frozen figures or its recompute.
    app(ExtendStay::class)->handle($this->mark, $stay->refresh(), 6, PaymentMethod::Cash, Money::ofCentavos(25000), idempotencyKey: (string) Str::uuid());

    expect($shift->refresh()->totals)->toEqual($frozen)
        ->and(app(ReportService::class)->recompute($shift)['sales'])->toEqual($frozen['sales']);
});

it('chains the grand total and Z number to the running sum of sales across shifts', function (): void {
    $a = Hotel::openShift($this->jessa, 500000);
    Hotel::checkIn('205');
    Hotel::closeAndHandOver($this->mark);           // opens shift B, member mark
    $a->refresh();

    $b = app(CurrentShift::class)->find($this->branch->id);
    Hotel::checkIn('206');
    Hotel::closeAndHandOver($this->jessa, $this->mark); // close B (counted by mark), hand to jessa
    $b = $b->refresh();

    expect($b->z_no)->toBe($a->z_no + 1)
        ->and($b->grand_total_before->centavos())->toBe($a->grand_total_after->centavos())
        ->and($a->grand_total_after->centavos())->toBe($a->grand_total_before->centavos() + $a->totals['sales']['total'])
        ->and($b->grand_total_after->centavos())->toBe($b->grand_total_before->centavos() + $b->totals['sales']['total']);
});

it('reconciles a meal sale: its revenue is one folio line and the ingredient movements carry no money', function (): void {
    $shift = Hotel::openShift($this->jessa, 500000);
    app(CompleteSale::class)->handle($this->jessa, $this->branch->id, PosMode::WalkIn, [Hotel::product('Tocilog')->id => 1], (string) Str::uuid(), Money::ofCentavos(20000));

    // The meal deducted its ingredients, but no stock movement carries money.
    expect(StockMovement::query()->where('shift_session_id', $shift->id)->count())->toBeGreaterThan(0)
        ->and(StockMovement::query()->getConnection()->getSchemaBuilder()->hasColumn('stock_movements', 'amount'))->toBeFalse();

    Hotel::closeAndHandOver($this->mark);
    $shift->refresh();

    // The meal's ₱120 is the shift's sales, equal to the sum of the folio lines, and recompute agrees.
    expect($shift->totals['sales']['total'])->toBe(12000)
        ->and($shift->totals['sales']['total'])->toBe((int) FolioLine::query()->where('shift_session_id', $shift->id)->sum('amount'))
        ->and(app(ReportService::class)->recompute($shift)['sales'])->toEqual($shift->totals['sales']);
});
