<?php

declare(strict_types=1);

use App\Actions\Pos\CompleteSale;
use App\Actions\Pos\ReceiveStock;
use App\Actions\Pos\RecordWastage;
use App\Actions\Pos\VoidSale;
use App\Enums\ApprovalAction;
use App\Enums\PosMode;
use App\Enums\ReasonKind;
use App\Enums\StockMovementKind;
use App\Exceptions\ActionRefused;
use App\Models\Approval;
use App\Models\ShiftSession;
use App\Models\StockMovement;
use App\Queries\ReportQuery;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Tests\Support\Hotel;

/*
| Stock (20): ReceiveStock and RecordWastage, APP_FLOW §4.14, and ReportQuery::stock — TRD §10
| "stock: opening + in − sold − wastage = closing, and closing = next opening". Chips: 10 on hand.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00'); // 11:00 AM Manila, AM shift
    $this->shift = Hotel::openShift();
    $this->chips = Hotel::product('Chips');
    $this->receive = fn (int $qty, ?string $note = null) => app(ReceiveStock::class)->handle(Hotel::frontdesk(), Hotel::branch()->id, $this->chips->id, $qty, $note);
    $this->waste = fn (int $qty, string $reason = 'Damaged', string $note = 'Bag torn', mixed ...$approval) => app(RecordWastage::class)->handle(
        Hotel::frontdesk(), Hotel::branch()->id, $this->chips->id, $qty, Hotel::reason(ReasonKind::Wastage, $reason), $note, ...$approval,
    );
    $this->row = fn (ShiftSession $shift, string $name = 'Chips') => collect(app(ReportQuery::class)->stock($shift))->firstWhere('name', $name);
});

it('receives stock: a receive movement with the balance after, the shift and the person, and stock on hand', function (): void {
    $movement = ($this->receive)(24, 'DR 4471');

    expect([$movement->kind, $movement->quantity, $movement->balance_after, $movement->shift_session_id, $movement->created_by, $movement->note])
        ->toBe([StockMovementKind::Receive, 24, 34, $this->shift->id, Hotel::frontdesk()->id, 'DR 4471'])
        ->and($this->chips->fresh()->stock_on_hand)->toBe(34);
});

it('refuses to receive zero pieces or a product that keeps no stock', function (): void {
    expect(fn () => ($this->receive)(0))->toThrow(ActionRefused::class);
    expect(fn () => app(ReceiveStock::class)->handle(Hotel::frontdesk(), Hotel::branch()->id, Hotel::product('Broken glass')->id, 5))->toThrow(ActionRefused::class, 'does not keep stock');
    expect(StockMovement::query()->count())->toBe(0);
});

it('records wastage with its reason and note as a negative movement', function (): void {
    $movement = ($this->waste)(2);

    expect([$movement->kind, $movement->quantity, $movement->balance_after, $movement->reason_id, $movement->note])
        ->toBe([StockMovementKind::Wastage, -2, 8, Hotel::reason(ReasonKind::Wastage, 'Damaged')->id, 'Bag torn'])
        ->and($this->chips->fresh()->stock_on_hand)->toBe(8);
});

it('refuses wastage without a note, or that would take stock below zero', function (): void {
    expect(fn () => ($this->waste)(1, 'Damaged', ' '))->toThrow(ActionRefused::class);
    expect(fn () => ($this->waste)(11))->toThrow(ActionRefused::class, 'Only 10 Chips on hand');
    expect([StockMovement::query()->count(), $this->chips->fresh()->stock_on_hand])->toBe([0, 10]);
});

it('asks a supervisor PIN for a wastage reason that needs a manager', function (): void {
    expect(fn () => ($this->waste)(1, 'Other', 'Rats got into the box'))->toThrow(ActionRefused::class, 'needs a supervisor PIN');
    expect(StockMovement::query()->count())->toBe(0);

    $movement = ($this->waste)(1, 'Other', 'Rats got into the box', Hotel::supervisor(), Hotel::PIN, Hotel::device());

    $approval = Approval::query()->sole();
    expect([$approval->action, $approval->subject_type, $approval->subject_id])->toBe([ApprovalAction::ReasonRequiresManager, 'stock_movement', $movement->id]);
});

it('stock: opening + in − sold − wastage = closing, and closing = next opening', function (): void {
    // AM shift: 10 on hand at open; +24 in, 3 sold (and a 1-piece sale voided), 2 wasted.
    ($this->receive)(24);
    $sell = fn (int $qty) => app(CompleteSale::class)->handle(Hotel::frontdesk(), Hotel::branch()->id, PosMode::WalkIn, [$this->chips->id => $qty], (string) Str::uuid(), Money::ofPesos(500));
    $sell(3);
    $voided = $sell(1);
    app(VoidSale::class)->handle(Hotel::frontdesk(), $voided, Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount'), Hotel::supervisor(), Hotel::PIN, Hotel::device());
    ($this->waste)(2);

    $am = ($this->row)($this->shift);
    expect([$am['opening'], $am['in'], $am['sold'], $am['wastage'], $am['closing']])->toBe([10, 24, 3, 2, 29])
        ->and($am['opening'] + $am['in'] - $am['sold'] - $am['wastage'])->toBe($am['closing'])
        ->and($am['closing'])->toBe($this->chips->fresh()->stock_on_hand);

    // Hand over; the PM shift sells 4 more. The AM figures do not move, PM opens where AM closed.
    $pm = Hotel::closeAndHandOver(Hotel::user('rhea.bautista'));
    app(CompleteSale::class)->handle(Hotel::user('rhea.bautista'), Hotel::branch()->id, PosMode::WalkIn, [$this->chips->id => 4], (string) Str::uuid(), Money::ofPesos(500));

    $amAgain = ($this->row)($this->shift->fresh());
    $pmRow = ($this->row)($pm);
    expect($amAgain)->toBe($am)
        ->and($pmRow['opening'])->toBe($am['closing'])
        ->and([$pmRow['in'], $pmRow['sold'], $pmRow['wastage'], $pmRow['closing']])->toBe([0, 4, 0, 25])
        ->and($pmRow['closing'])->toBe($this->chips->fresh()->stock_on_hand);
});
