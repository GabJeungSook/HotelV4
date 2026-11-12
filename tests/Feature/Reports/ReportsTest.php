<?php

declare(strict_types=1);

use App\Actions\Pos\CompleteSale;
use App\Actions\Pos\ReceiveStock;
use App\Actions\Pos\RecordWastage;
use App\Actions\Stays\AddCharges;
use App\Actions\Stays\CheckOutGuest;
use App\Enums\PaymentMethod;
use App\Enums\PosMode;
use App\Enums\ReasonKind;
use App\Enums\ShiftStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Queries\ReportQuery;
use App\Services\ReportService;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoShiftSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Hotel;

/*
| TRD §10 "Reports": every report reads the same ledger through ReportService, so they cannot
| disagree. The demo shift (Z-0629) and shifts run through the real actions are used.
*/

beforeEach(function (): void {
    $this->reports = app(ReportService::class);
});

it('shift report equals ledger', function (): void {
    $this->seed([DatabaseSeeder::class, DemoShiftSeeder::class]);
    $am = ShiftSession::query()->where('z_no', 629)->sole();
    $report = $this->reports->shift($am);
    $sales = collect($report['sales'])->keyBy('key');
    $line = fn (array $kinds): int => (int) FolioLine::query()->where('shift_session_id', $am->id)->whereIn('kind', $kinds)->sum('amount');

    expect($report['checks'])->toBe([])
        ->and($report['sales_total'])->toBe((int) FolioLine::query()->where('shift_session_id', $am->id)->sum('amount'))->toBe(1816000)
        ->and($sales['room']['amount'])->toBe($line(['room', 'discount']))->toBe(1435000)
        ->and($sales['extension']['amount'])->toBe($line(['extension']))
        ->and($sales['food_drink']['amount'])->toBe($line(['food_drink']))
        ->and($report['unpaid']['collected'])->toBe((int) PaymentAllocation::query()->whereIn('payment_id', Payment::query()->where('shift_session_id', $am->id)->pluck('id'))->sum('amount'))
        ->and(array_map(fn (array $c): array => [$c['label'], $c['amount']], $report['cash']))->toBe([
            ['Opening cash', 500000], ['Collected from guests', 1804500], ['Deposits taken (27)', 540000], ['Deposits returned (23)', -460000],
            ['Expenses (3)', -65000], ['Remittance (1)', -1000000],
        ])
        ->and($report['expected'])->toBe(1319500)->and($report['counted'])->toBe(1314500)->and($report['variance'])->toBe(-5000)
        ->and($report['guests'])->toBe(['forwarded_in' => 19, 'checked_in' => 27, 'checked_out' => 23, 'forwarded_out' => 23, 'cancelled' => 0])
        ->and($report['deposits']['held_close'])->toBe(460000)
        ->and($report['unpaid'])->toBe(['forwarded_in' => 22500, 'charged' => 1816000, 'collected' => 1804500, 'forwarded_out' => 34000]);

    // The ledger rows behind a figure add up to it.
    $ledger = app(ReportQuery::class);
    expect($ledger->ledger([$am->id], 'sales:room')['total'])->toBe(1435000)
        ->and($ledger->ledger([$am->id], 'deposits_taken')['total'])->toBe(540000)
        ->and($ledger->ledger([$am->id], 'expenses')['total'])->toBe(65000)
        ->and($ledger->ledger([$am->id], 'cash_collected')['total'])->toBe(1804500)
        ->and($ledger->ledger([$am->id], 'discount:Promo')['total'])->toBe(-20000);
});

it('Z-report gross equals shift sales; floor columns sum to total; "No room" equals walk-in POS', function (): void {
    $this->seed([DatabaseSeeder::class, DemoShiftSeeder::class]);
    $am = ShiftSession::query()->where('z_no', 629)->sole();
    $rooms = $this->reports->zRooms($am);
    $row = fn (string $key): array => collect($rooms['rows'])->firstWhere('key', $key);
    $floors = collect($rooms['columns'])->pluck('key', 'label');

    expect($rooms['checks'])->toBe([])
        ->and($rooms['gross']['total'])->toBe($am->totals['sales']['total'])->toBe(1816000)
        ->and(array_sum($rooms['gross']['cells']))->toBe($rooms['gross']['total'])
        ->and($rooms['gross']['cells']['none'])->toBe((int) FolioLine::query()->where('shift_session_id', $am->id)->whereNull('stay_id')->sum('amount'))->toBe(35000)
        ->and(array_values($row('room')['cells']))->toBe([560000, 735000, 140000, 0])
        ->and(array_values($row('foods')['cells']))->toBe([18000, 25000, 6000, 15000])
        ->and(array_values($row('misc')['cells']))->toBe([13000, 20000, 5000, 0])
        ->and(array_values($rooms['gross']['cells']))->toBe([686000, 912000, 183000, 35000])
        ->and(array_values($rooms['deposits']['cells']))->toBe([200000, 220000, 40000, 0])
        ->and($floors->keys()->all())->toBe(['Floor 1', 'Floor 2', 'Floor 3', 'No room']);

    // The floor 2 chart: forwarded guests in red with only this shift's charges, EXT marks, vacant rooms.
    $chart = collect(collect($rooms['charts'])->firstWhere('floor', 'Floor 2')['rows'])->keyBy('room');
    expect($chart['202'])->toMatchArray(['guest' => 'S. Reyes', 'forwarded' => true, 'room_charge' => 0, 'foods' => 4500, 'drinks' => 2500, 'deposit' => 20000])
        ->and($chart['203'])->toMatchArray(['guest' => 'L. Mendoza', 'forwarded' => false, 'room_charge' => 45000, 'deposit' => 0])
        ->and($chart['206'])->toMatchArray(['guest' => 'N. Santos', 'forwarded' => true, 'extended' => true, 'ext' => 40000, 'foods' => 9000, 'drinks' => 2500, 'deposit' => 20000])
        ->and($chart['207'])->toBe(['room' => '207', 'vacant' => true]);
});

it('day = sum of its shifts', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 00:00:00');
    Hotel::openShift();
    Hotel::checkIn('205', 12);
    app(CompleteSale::class)->handle(Hotel::frontdesk(), Hotel::branch()->id, PosMode::WalkIn, [Hotel::product('Chips')->id => 1], (string) Str::uuid(), Money::ofPesos(50));
    $this->travelClock(700);
    $pm = Hotel::closeAndHandOver(Hotel::user('mark.sumagaysay'));
    Hotel::checkIn('101', 6, by: Hotel::user('mark.sumagaysay'));
    $this->travelClock(700);
    Hotel::closeAndHandOver(Hotel::frontdesk(), Hotel::user('mark.sumagaysay'));

    $daily = $this->reports->daily(Hotel::branch()->id, '2026-11-12');
    $shifts = ShiftSession::query()->whereDate('business_date', '2026-11-12')->orderBy('opened_at')->get();

    expect($daily['columns'])->toHaveCount(2)
        ->and(array_column($daily['columns'], 'total'))->toBe($shifts->map(fn (ShiftSession $s): int => $s->totals['sales']['total'])->all())
        ->and($daily['total']['total'])->toBe(array_sum(array_column($daily['columns'], 'total')))
        ->and($daily['total']['total'])->toBe((int) FolioLine::query()->whereIn('shift_session_id', $shifts->pluck('id'))->sum('amount'))
        ->and($daily['checks'])->toBe([])
        ->and($daily['reconcile']['differences'])->toBe([]);

    foreach ($daily['rows'] as $row) {
        expect($daily['total']['cells'][$row['key']])->toBe(array_sum(array_map(fn (array $c): int => $c['cells'][$row['key']], $daily['columns'])), $row['label']);
    }

    // A week is the sum of its days.
    $week = $this->reports->daily(Hotel::branch()->id, '2026-11-12', 'week');
    expect($week['columns'])->toHaveCount(7)->and($week['total']['total'])->toBe($daily['total']['total']);
});

it('owner summary = sum of branches', function (): void {
    $this->seed([DatabaseSeeder::class, DemoShiftSeeder::class]);
    $second = Branch::factory()->create(['name' => 'Hotel Sample Polomolok']);
    foreach ([[1, 1200000, -3000], [2, 900000, 1000]] as [$z, $sales, $variance]) {
        ShiftSession::factory()->closed($z)->create([
            'branch_id' => $second->id, 'business_date' => '2026-11-1'.$z, 'z_no' => $z, 'variance_amount' => $variance,
            'counted_cash' => 500000 + $variance, 'variance_note' => 'Sample', 'totals' => ['sales' => ['total' => $sales], 'guests' => ['checked_in' => 10 * $z, 'forwarded_out' => 4]],
        ]);
    }

    $summary = $this->reports->summary([Hotel::branch()->id, $second->id], '2026-11-04', '2026-11-12');
    $rows = collect($summary['rows'])->keyBy('name');

    expect($rows['Hotel Dalisay GenSan']['sales'])->toBe(ShiftSession::query()->where('branch_id', Hotel::branch()->id)->where('status', ShiftStatus::Closed)->get()->sum(fn ($s): int => $s->totals['sales']['total']))
        ->and($rows['Hotel Sample Polomolok']['sales'])->toBe(2100000)
        ->and($rows['Hotel Sample Polomolok']['short'])->toBe(3000)->and($rows['Hotel Sample Polomolok']['over'])->toBe(1000)
        ->and($summary['total']['short'])->toBe(3000 + 5000)->and($summary['total']['over'])->toBe(1000);

    foreach (['sales', 'checkins', 'room_days', 'occupied', 'short', 'over', 'shifts'] as $key) {
        expect($summary['total'][$key])->toBe($rows->sum($key), $key);
    }

    // Placeholder shifts with hand-written totals have no ledger behind them: removed before the invariants run.
    DB::table('shift_sessions')->where('branch_id', $second->id)->delete();
});

it('occupancy: start + in − out = end', function (): void {
    $this->seed([DatabaseSeeder::class, DemoShiftSeeder::class]);
    $occ = $this->reports->occupancy(Hotel::branch()->id, '2026-11-11');

    expect([$occ['start'], $occ['in'], $occ['out'], $occ['end']])->toBe([19, 27, 23, 23])
        ->and($occ['start'] + $occ['in'] - $occ['out'])->toBe($occ['end'])
        ->and($occ['checks'])->toBe([])
        ->and($occ['total']['rooms'])->toBe(46)
        ->and($occ['total']['checkins'])->toBe(27)
        ->and($occ['lists']['end'])->toHaveCount(23)
        ->and($occ['end'])->toBe(Stay::query()->where('state', 'checked_in')->count());
});

it('stock: opening + in − sold − wastage = closing, and closing = next opening', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 00:00:00');
    Hotel::openShift();
    $chips = Hotel::product('Chips');
    $stay = Hotel::checkIn('205', 12);
    app(AddCharges::class)->handle(Hotel::frontdesk(), $stay, [$chips->id => 2]);
    app(ReceiveStock::class)->handle(Hotel::frontdesk(), Hotel::branch()->id, $chips->id, 12, 'DR 1');
    app(RecordWastage::class)->handle(Hotel::frontdesk(), Hotel::branch()->id, $chips->id, 1, Hotel::reason(ReasonKind::Wastage, 'Damaged'), 'Torn');
    $this->travelClock(700);
    Hotel::closeAndHandOver(Hotel::user('mark.sumagaysay'));
    $this->travelClock(700);
    $mark = Hotel::user('mark.sumagaysay');
    Hotel::closeAndHandOver(Hotel::frontdesk(), $mark); // PM 12 Nov closes, AM 13 Nov opens
    app(CompleteSale::class)->handle(Hotel::frontdesk(), Hotel::branch()->id, PosMode::WalkIn, [$chips->id => 3], (string) Str::uuid(), Money::ofPesos(200));
    app(CheckOutGuest::class)->handle(Hotel::frontdesk(), $stay, payWith: PaymentMethod::Cash, amountGiven: Money::ofPesos(70));

    $day1 = collect($this->reports->inventory(Hotel::branch()->id, '2026-11-12')['rows'])->firstWhere('name', 'Chips');
    $day2 = collect($this->reports->inventory(Hotel::branch()->id, '2026-11-13')['rows'])->firstWhere('name', 'Chips');

    expect($day1)->toMatchArray(['opening' => 10, 'in' => 12, 'sold' => 2, 'wastage' => 1, 'closing' => 19, 'sales' => 7000])
        ->and($day1['opening'] + $day1['in'] - $day1['sold'] - $day1['wastage'])->toBe($day1['closing'])
        ->and($day2['opening'])->toBe($day1['closing'])
        ->and($day2)->toMatchArray(['sold' => 3, 'closing' => 16])
        ->and($day2['closing'])->toBe($chips->fresh()->stock_on_hand);
});

it('reconcile job flags a tampered row', function (): void {
    $this->seed([DatabaseSeeder::class, DemoShiftSeeder::class]);
    $this->freezeClock('2026-11-12 01:00:00'); // 9:00 AM Manila on 12 Nov: yesterday is 11 Nov

    expect(Artisan::call('homi:reconcile-business-day'))->toBe(0)
        ->and(AuditLog::query()->where('action', 'reconcile.mismatch')->count())->toBe(0);

    // Someone edits a room line of Z-0629 straight in the database.
    $am = ShiftSession::query()->where('z_no', 629)->sole();
    $line = FolioLine::query()->where('shift_session_id', $am->id)->where('kind', 'room')->orderBy('id')->first();
    DB::table('folio_lines')->where('id', $line->id)->update(['amount' => $line->amount->centavos() - 10000, 'unit_amount' => $line->unit_amount->centavos() - 10000]);

    expect(Artisan::call('homi:reconcile-business-day'))->toBe(1);
    $log = AuditLog::query()->where('action', 'reconcile.mismatch')->sole();
    $figures = collect($log->after_json['differences'])->keyBy('figure');

    expect($log->after_json['date'])->toBe('2026-11-11')
        ->and($figures['sales.total'])->toMatchArray(['shift_id' => $am->id, 'stored' => 1816000, 'recomputed' => 1806000])
        ->and($figures['day.net_sales'])->toMatchArray(['stored' => 1816000, 'recomputed' => 1806000]);

    // Put the row back so the invariants hold after the test.
    DB::table('folio_lines')->where('id', $line->id)->update(['amount' => $line->amount->centavos(), 'unit_amount' => $line->unit_amount->centavos()]);
});
