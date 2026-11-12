<?php

declare(strict_types=1);

use App\Enums\LineKind;
use App\Enums\RoomStatus;
use App\Enums\ShiftStatus;
use App\Enums\StayState;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Room;
use App\Models\ShiftForwardedStay;
use App\Models\ShiftHandover;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\StockMovement;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Demo\Ledger;
use Database\Seeders\Demo\ShiftTotals;
use Database\Seeders\DemoShiftSeeder;

/*
| DemoShiftSeeder recreates the AM shift of 11 Nov 2026 as drawn on screens 26,
| 37, 37b and 37c (PRD §13 item 7). Every printed figure is checked here, from
| the frozen totals and from the ledger rows behind them.
*/

beforeEach(function (): void {
    $this->seed([DatabaseSeeder::class, DemoShiftSeeder::class]);
    $this->am = ShiftSession::query()->where('z_no', 629)->sole();
    $this->t = $this->am->totals;
});

it('closes the AM shift of 11 Nov as Z-0629 with the mockup cash figures', function (): void {
    $am = $this->am;

    expect($am->status)->toBe(ShiftStatus::Closed)
        ->and($am->period_name)->toBe('AM')
        ->and($am->business_date->toDateString())->toBe('2026-11-11')
        ->and(Ledger::at('07:58')->equalTo($am->opened_at))->toBeTrue()
        ->and(Ledger::at('20:04')->equalTo($am->closed_at))->toBeTrue()
        ->and($am->opening_cash->centavos())->toBe(500000)
        ->and($am->expected_cash->centavos())->toBe(1319500)
        ->and($am->counted_cash->centavos())->toBe(1314500)
        ->and($am->variance_amount->centavos())->toBe(-5000)
        ->and($am->variance_note)->toBe('Gave wrong change to Room 203, ₱50.')
        ->and($am->grand_total_before->centavos())->toBe(1148234000)
        ->and($am->grand_total_after->centavos())->toBe(1150050000)
        ->and($am->members()->with('user')->get()->pluck('user.name')->all())->toBe(['Jessa Mae Dela Cruz', 'Rhea Bautista']);
});

it('walks the 37b sales-and-cash column with every bold line equal to its arithmetic', function (): void {
    $t = $this->t;
    $discounts = array_sum(array_column($t['discounts'], 'amount'));
    $netSales = $t['gross_sales'] - $discounts;
    $paidEarlier = $t['unpaid']['forwarded_in'];
    $collected = $netSales - $t['unpaid']['forwarded_out'] + $paidEarlier;
    $cashCollected = $collected - $t['collected']['gcash'] - $t['collected']['card'] - $t['collected']['deposit'];
    $fromShift = $cashCollected + $t['deposits']['taken'] - $t['deposits']['returned'] - $t['cash']['expenses'] - $t['cash']['remittance'] + $t['cash']['shortage_payments'];
    $expected = $fromShift + $t['cash']['opening'];

    expect($t['gross_sales'])->toBe(1846000)
        ->and($t['discounts'])->toEqual([['name' => 'Promo', 'label' => 'Promo ₱50', 'count' => 4, 'amount' => 20000], ['name' => 'Senior citizen', 'label' => 'Senior citizen 20%', 'count' => 1, 'amount' => 10000]])
        ->and($netSales)->toBe(1816000)->and($t['sales']['total'])->toBe($netSales)
        ->and($t['unpaid']['forwarded_out'])->toBe(34000)
        ->and($paidEarlier)->toBe(22500)
        ->and($collected)->toBe(1804500)->and($t['collected']['cash'])->toBe($collected)
        ->and($cashCollected)->toBe(1804500)
        ->and($t['deposits']['taken'])->toBe(540000)
        ->and($t['deposits']['returned'])->toBe(460000)
        ->and($t['cash']['expenses'])->toBe(65000)
        ->and($t['cash']['remittance'])->toBe(1000000)
        ->and($fromShift)->toBe(819500)
        ->and($expected)->toBe(1319500)->and($this->am->expected_cash->centavos())->toBe($expected)
        ->and($this->am->counted_cash->centavos() - $expected)->toBe(-5000);
});

it('has the 37 shift report sales and the three roll-forwards balancing', function (): void {
    $t = $this->t;

    // Room is shown net of its discounts on 37 and 37c: ₱14,650 − ₱300 = ₱14,350.
    expect($t['sales']['room'] + $t['sales']['discount'])->toBe(1435000)
        ->and(array_intersect_key($t['sales'], array_flip(['extension', 'transfer_difference', 'food_drink', 'amenity', 'damage', 'forfeited_deposit'])))
        ->toEqual(['extension' => 190000, 'transfer_difference' => 15000, 'food_drink' => 138000, 'amenity' => 18000, 'damage' => 20000, 'forfeited_deposit' => 0])
        ->and($t['sales_counts'])->toEqual(['room' => 27, 'extension' => 7, 'transfer_difference' => 1, 'food_drink' => 41, 'amenity' => 6, 'damage' => 2]);

    $g = $t['guests'];
    expect([$g['forwarded_in'], $g['checked_in'], $g['checked_out'], $g['forwarded_out']])->toBe([19, 27, 23, 23])
        ->and($g['forwarded_in'] + $g['checked_in'] - $g['checked_out'])->toBe($g['forwarded_out']);

    $d = $t['deposits'];
    expect([$d['held_open'], $d['forfeited'], $d['applied'], $d['held_close']])->toBe([380000, 0, 0, 460000])
        ->and($d['held_open'] + $d['taken'] - $d['returned'] - $d['forfeited'] - $d['applied'])->toBe($d['held_close']);

    $u = $t['unpaid'];
    expect($u['forwarded_in'] + $u['charged'] - $u['collected'])->toBe($u['forwarded_out']);
});

it('has the 37b side panels: by front desk, exceptions, documents', function (): void {
    $t = $this->t;

    expect(array_map(fn (array $row): array => [$row['name'], $row['checkins'], $row['cash'], $row['given_back']], $t['by_user']))
        ->toBe([['Jessa Mae Dela Cruz', 15, 1048000, 0], ['Rhea Bautista', 12, 756500, 70000]])
        ->and($t['exceptions'])->toEqual([
            'cancelled_stays' => ['count' => 1, 'amount' => 70000],
            'voided_lines' => ['count' => 0, 'amount' => 0],
            'cash_refunds' => ['count' => 1, 'amount' => 70000],
            'transfers' => ['count' => 1, 'amount' => 15000],
            'forfeited_deposits' => ['count' => 0, 'amount' => 0],
            'voided_cash_movements' => ['count' => 1, 'amount' => 300000],
            'reprints' => ['count' => 2],
            'force_closed' => false,
        ])
        ->and($t['documents'])->toEqual([
            'stays' => ['first' => 'DGS-26-004112', 'last' => 'DGS-26-004138', 'count' => 27],
            'pos_orders' => ['first' => 'P-008821', 'last' => 'P-008861', 'count' => 41],
        ])
        ->and($t['vat'])->toBeNull();
});

it('matches the 37c floor table from the ledger rows', function (): void {
    $lines = FolioLine::query()->where('shift_session_id', $this->am->id)->with(['room.floor', 'product'])->get();
    $column = fn (FolioLine $l): string => $l->room?->floor->label ?? 'none';
    $row = function (FolioLine $l): string {
        return match ($l->kind) {
            LineKind::Room, LineKind::Discount => 'room',
            LineKind::Extension => 'extend',
            LineKind::TransferDifference => 'transfer',
            default => $l->product?->report_group->value ?? 'misc',
        };
    };

    $table = [];
    foreach ($lines as $line) {
        $table[$row($line)][$column($line)] = ($table[$row($line)][$column($line)] ?? 0) + $line->amount->centavos() / 100;
    }

    expect($table['room'])->toEqual(['1F' => 5600, '2F' => 7350, '3F' => 1400])
        ->and($table['extend'])->toEqual(['1F' => 750, '2F' => 900, '3F' => 250])
        ->and($table['transfer'])->toEqual(['2F' => 150])
        ->and($table['foods'])->toEqual(['1F' => 180, '2F' => 250, '3F' => 60, 'none' => 150])
        ->and($table['drinks'])->toEqual(['1F' => 200, '2F' => 270, '3F' => 70, 'none' => 200])
        ->and($table['misc'])->toEqual(['1F' => 130, '2F' => 200, '3F' => 50]);

    $held = ShiftForwardedStay::query()->where('shift_session_id', $this->am->id)->with('room.floor')->get()
        ->groupBy(fn (ShiftForwardedStay $f): string => $f->room->floor->label)
        ->map(fn ($rows): int => (int) $rows->sum(fn (ShiftForwardedStay $f): int => $f->key_deposit_held->centavos()) / 100)
        ->sortKeys()->all();
    expect($held)->toEqual(['1F' => 2000, '2F' => 2200, '3F' => 400]);
});

it('has the 37c floor 2 sample rows', function (): void {
    $shiftLines = fn (string $guest): array => FolioLine::query()->where('shift_session_id', $this->am->id)
        ->whereRelation('stay', 'guest_name', $guest)->with('product')->get()
        ->groupBy(fn (FolioLine $l): string => $l->product?->report_group->value ?? $l->kind->value)
        ->map(fn ($rows): int => (int) ($rows->sum(fn (FolioLine $l): int => $l->amount->centavos()) / 100))->sortKeys()->all();

    expect($shiftLines('Sonia Reyes'))->toBe(['drinks' => 25, 'foods' => 45])
        ->and($shiftLines('Liza Mendoza'))->toBe(['room' => 450])
        ->and($shiftLines('Rico Manalastas'))->toBe(['drinks' => 25, 'foods' => 90, 'room' => 650])
        ->and($shiftLines('Nestor Santos'))->toBe(['drinks' => 25, 'extension' => 400, 'foods' => 90]);

    $mendoza = Stay::query()->where('guest_name', 'Liza Mendoza')->sole();
    expect($mendoza->room->number)->toBe('203')
        ->and(Ledger::at('08:15')->equalTo($mendoza->checked_in_at))->toBeTrue()
        ->and(Ledger::at('13:26')->equalTo($mendoza->checked_out_at))->toBeTrue()
        ->and(Stay::query()->whereRelation('room', 'number', '207')->exists())->toBeFalse();
});

it('links the shifts: Z-0628 forwarded 19 guests in, the PM shift is open with the counted cash', function (): void {
    $pm10 = ShiftSession::query()->where('z_no', 628)->sole();
    $pm11 = ShiftSession::query()->where('status', ShiftStatus::Open)->sole();

    expect($pm10->business_date->toDateString())->toBe('2026-11-10')
        ->and($pm10->grand_total_after->centavos())->toBe($this->am->grand_total_before->centavos())
        ->and($pm10->forwardedStays()->count())->toBe(19)
        ->and((int) $pm10->forwardedStays()->get()->sum(fn ($f): int => $f->unpaid_amount->centavos()))->toBe(22500)
        ->and(ShiftHandover::query()->where('from_shift_id', $pm10->id)->value('to_shift_id'))->toBe($this->am->id)
        ->and($pm11->period_name)->toBe('PM')->and($pm11->opening_cash->centavos())->toBe(1314500)
        ->and($this->am->handoverOut->to_shift_id)->toBe($pm11->id)
        ->and($this->am->handoverOut->difference_amount->centavos())->toBe(0);
});

it('stores totals that the ledger rebuilds to the same figures', function (): void {
    $pm10 = ShiftSession::query()->where('z_no', 628)->sole();
    $rebuilt = ShiftTotals::build($this->am, $pm10->forwardedStays()->get(), $this->am->forwardedStays()->get());

    expect($rebuilt)->toEqual($this->t);
});

it('leaves the data consistent: TRD §8 invariants 1–5 and 8', function (): void {
    // 1. At most one open shift per branch.
    expect(ShiftSession::query()->whereIn('status', [ShiftStatus::Open, ShiftStatus::Closing])->count())->toBe(1);

    // 2. Occupied rooms and checked-in stays point at each other one to one.
    $occupied = Room::query()->where('status', RoomStatus::Occupied)->pluck('id')->sort()->values()->all();
    $inHouse = Stay::query()->where('state', StayState::CheckedIn)->pluck('room_id')->sort()->values()->all();
    expect($occupied)->toBe($inHouse)->and($inHouse)->toHaveCount(23);

    // 3. Every checked-in stay has a room line.
    expect(Stay::query()->where('state', StayState::CheckedIn)->whereDoesntHave('folioLines', fn ($q) => $q->where('kind', LineKind::Room))->count())->toBe(0);

    // 4. Every checked-out stay has balance 0 and no deposit held.
    foreach (Stay::query()->where('state', StayState::CheckedOut)->with(['folioLines.allocations', 'payments'])->get() as $stay) {
        $balance = $stay->folioLines->sum(fn (FolioLine $l): int => $l->amount->centavos() - (int) $l->allocations->sum(fn ($a): int => $a->amount->centavos()));
        $deposits = $stay->payments->whereNotNull('deposit_type')->sum(fn (Payment $p): int => $p->kind->value === 'deposit_applied' ? -$p->amount->centavos() : $p->amount->centavos());
        expect([$balance, $deposits])->toBe([0, 0], $stay->code);
    }

    // 5. stock_on_hand equals the last balance_after.
    foreach (Product::query()->whereHas('stockMovements')->get() as $product) {
        $last = StockMovement::query()->where('product_id', $product->id)->orderByDesc('occurred_at')->orderByDesc('id')->value('balance_after');
        expect($product->stock_on_hand)->toBe($last, $product->name);
    }

    // 8. Shift N's forwarded-out rows are shift N+1's forwarded-in rows (same stays).
    $pm10 = ShiftSession::query()->where('z_no', 628)->sole();
    $amIn = $pm10->forwardedStays()->pluck('stay_id')->all();
    $amStays = Stay::query()->whereIn('id', $amIn)->get();
    expect($amStays->every(fn (Stay $s): bool => $s->checked_in_at->lessThan($this->am->opened_at)))->toBeTrue();
});
