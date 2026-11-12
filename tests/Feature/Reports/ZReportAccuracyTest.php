<?php

declare(strict_types=1);

use App\Actions\Pos\CompleteSale;
use App\Actions\Reports\CloseDay;
use App\Actions\Shifts\VoidCashMovement;
use App\Actions\Stays\AddCharges;
use App\Actions\Stays\CancelStay;
use App\Actions\Stays\CheckOutGuest;
use App\Actions\Stays\ExtendStay;
use App\Actions\Stays\ReserveRoom;
use App\Actions\Stays\TakePayment;
use App\Actions\Stays\TransferRoom;
use App\Actions\Stays\VoidLine;
use App\Enums\CashMovementKind;
use App\Enums\PaymentMethod;
use App\Enums\PosMode;
use App\Enums\ReasonKind;
use App\Enums\StayKind;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Services\Reports\Totals;
use App\Services\ReportService;
use App\Support\Clock;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Str;
use Tests\Support\Hotel;

/*
| The Z-report must be right for every kind of money row, not only for the demo shift (Gab,
| 12 Nov: "the reports should be accurate"). Two shifts are run through the real actions with
| cash, GCash, card and deposit payments, change kept as deposit, money paid ahead by GCash, a
| downgrade credit, a lost key, an unclaimed deposit, a void, a cancelled stay, a walk-in POS
| sale, expenses (one voided), a remittance, a short close and a shortage payment. For each
| shift the paper's column must walk exactly to the expected cash CashMath stored at the close,
| the preview must equal the closed paper, and every report of the day must agree with it.
*/

function zLines(array $paper): array
{
    return collect(collect($paper['sections'])->firstWhere('key', 'sales_cash')['lines'])
        ->mapWithKeys(fn (array $l): array => [$l['label'] => $l['amount']])->all();
}

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    Hotel::branch()->update(['payment_methods' => ['cash', 'gcash', 'card']]);
    $this->freezeClock('2026-11-12 00:05:00'); // 8:05 AM Manila, AM 12 Nov
    $this->reports = app(ReportService::class);
});

it('walks every kind of money row to the stored expected cash, shift after shift', function (): void {
    $jessa = Hotel::frontdesk();
    $carol = Hotel::supervisor();
    $mark = Hotel::user('mark.sumagaysay');
    $device = Hotel::device();
    $am = Hotel::openShift($jessa, 500000);

    // Change kept as a guest deposit (cash), charges, part cash, GCash, then pay from the guest deposit.
    $a = Hotel::checkIn('205', 12, tendered: 100000, keepChange: true);
    $this->travelClock(20);
    app(AddCharges::class)->handle($jessa, $a, [Hotel::product('Cup noodles')->id => 2, Hotel::product('Bottled water 500 ml')->id => 1]);
    app(TakePayment::class)->handle($jessa, $a, PaymentMethod::Cash, Money::ofPesos(50));
    app(TakePayment::class)->handle($jessa, $a, PaymentMethod::Gcash, Money::ofPesos(40), 'GC-1001');
    $water = FolioLine::query()->where('stay_id', $a->id)->where('description', 'Bottled water 500 ml')->sole();

    // A senior discount, an extension paid by card.
    $b = Hotel::checkIn('101', 6, discount: Hotel::discount('Senior citizen'), guest: 'Lito Ramos');
    app(ExtendStay::class)->handle($jessa, $b, 3, PaymentMethod::Card, Money::ofPesos(150), 'CARD-77');

    // A downgrade: Deluxe 206 to Standard 103, credit kept as guest deposit.
    $c = Hotel::checkIn('206', 12, guest: 'Nora Cruz');
    $this->travelClock(30);
    app(TransferRoom::class)->handle($jessa, $c, Hotel::room('103'), Hotel::transferReason(), $carol, Hotel::PIN, $device);

    // A lost key taken from the key deposit; an unclaimed deposit forfeited.
    $d = Hotel::checkIn('104', 6, guest: 'Ben Uy');
    $e = Hotel::checkIn('106', 6, guest: 'Ana Lim');
    $this->travelClock(60);
    app(CheckOutGuest::class)->handle($jessa, $d, keyReturned: false);
    app(CheckOutGuest::class)->handle($jessa, $e, depositClaimed: false);

    // A void with a cash refund, a cancelled stay with its refunds, a walk-in POS sale with change.
    app(VoidLine::class)->handle($jessa, $water, Hotel::reason(ReasonKind::VoidLine, 'Guest returned the item'), $carol, Hotel::PIN, $device);
    $f = Hotel::checkIn('107', 6, guest: 'Paolo Diaz');
    app(CancelStay::class)->handle($jessa, $f, Hotel::reason(ReasonKind::CancelStay, 'Guest changed mind'), $carol, Hotel::PIN, $device);
    app(CompleteSale::class)->handle($jessa, Hotel::branch()->id, PosMode::WalkIn, [Hotel::product('Chips')->id => 2], (string) Str::uuid(), Money::ofPesos(100));

    // Money paid ahead by GCash: a guest deposit that never reaches the drawer.
    app(ReserveRoom::class)->handle(
        $jessa, 'Mila Santos', null, RoomType::query()->where('name', 'Deluxe')->sole(), Hotel::room('214'),
        StayKind::Overnight, 1, app(Clock::class)->now()->addDays(2), (string) Str::uuid(), PaymentMethod::Gcash, Money::ofPesos(300), 'GC-2002',
    );

    // Expenses (one voided), a remittance.
    Hotel::movement(CashMovementKind::Expense, 12000);
    $wrong = Hotel::movement(CashMovementKind::Expense, 300000, note: 'Typed 3,000');
    app(VoidCashMovement::class)->handle($jessa, Hotel::branch()->id, $wrong->id, Hotel::reason(ReasonKind::VoidCashMovement, 'Typed wrong amount'), null, $carol, Hotel::PIN, $device);
    Hotel::movement(CashMovementKind::Remittance, 100000);

    // The preview of the open shift is built live and already adds up.
    $preview = $this->reports->zReport($am->fresh());
    expect($preview['preview'])->toBeTrue()
        ->and($preview['header']['number'])->toBeNull()
        ->and($preview['checks'])->toBe([])
        ->and($preview['figures']['expected'])->toBe(Hotel::expectedCash()->centavos());

    // Close 25 pesos short and hand over to Mark.
    $this->travelClock(600);
    $pm = Hotel::closeAndHandOver($mark, $jessa, offset: -2500);
    $am = $am->fresh();
    $paper = $this->reports->zReport($am);
    $lines = zLines($paper);

    // The closed paper says what the preview said, now with its Z number and the count.
    expect($paper['preview'])->toBeFalse()
        ->and($paper['header']['number'])->toBe('Z-'.str_pad((string) $am->z_no, 4, '0', STR_PAD_LEFT))
        ->and($paper['checks'])->toBe([])
        ->and($paper['figures']['expected'])->toBe($am->expected_cash->centavos())
        ->and(array_intersect_key($paper['figures'], array_flip(['gross_sales', 'net_sales', 'collected', 'cash_collected', 'cash_from_shift', 'expected'])))
        ->toBe(array_intersect_key($preview['figures'], array_flip(['gross_sales', 'net_sales', 'collected', 'cash_collected', 'cash_from_shift', 'expected'])))
        ->and($lines['Short'])->toBe(-2500);

    // Each line is the ledger: net sales are the shift's folio lines, the non-cash lines its
    // GCash, card and deposit payments, the drawer every cash payment of the shift.
    $cashIn = (int) Payment::query()->where('shift_session_id', $am->id)->where('method', PaymentMethod::Cash)->sum('amount');
    expect($lines['Net sales'])->toBe((int) FolioLine::query()->where('shift_session_id', $am->id)->sum('amount'))
        ->and($lines['Less GCash'])->toBe(-4000)
        ->and($lines['Less card'])->toBe(-15000)
        ->and($lines['Less paid from deposit'])->toBeLessThan(0)
        ->and($lines['Plus guest deposits, net'])->toBeGreaterThan(0)
        ->and($lines['Less expenses'])->toBe(-12000)
        ->and($lines['Less remittance'])->toBe(-100000)
        ->and($lines['Cash from this shift'])->toBe($cashIn - 12000 - 100000)
        ->and($lines['Expected in the drawer'])->toBe(500000 + $cashIn - 12000 - 100000);

    // The other reports of the shift agree with the paper.
    $report = $this->reports->shift($am);
    $rooms = $this->reports->zRooms($am);
    $walkIn = (int) FolioLine::query()->where('shift_session_id', $am->id)->whereNull('stay_id')->sum('amount');
    expect($report['checks'])->toBe([])
        ->and($report['expected'])->toBe($am->expected_cash->centavos())
        ->and($report['sales_total'])->toBe($paper['figures']['net_sales'])
        ->and($rooms['checks'])->toBe([])
        ->and($rooms['gross']['total'])->toBe($paper['figures']['net_sales'])
        ->and($rooms['gross']['cells']['none'])->toBe($walkIn)->and($walkIn)->toBe(7000);

    // Next shift: a shortage payment for the short shift, and a late void of an AM line.
    $this->travelClock(30);
    Hotel::movement(CashMovementKind::ShortagePayment, 2500, $mark, forShiftId: $am->id);
    $noodles = FolioLine::query()->where('stay_id', $a->id)->where('description', 'Cup noodles')->sole();
    app(VoidLine::class)->handle($mark, $noodles, Hotel::reason(ReasonKind::VoidLine, 'Guest returned the item'), $carol, Hotel::PIN, $device);
    $this->travelClock(600);
    Hotel::closeAndHandOver($jessa, $mark);
    $pm = $pm->fresh();
    $pmPaper = $this->reports->zReport($pm);

    // The late void is on the PM paper and lowers the PM grand total; the AM paper is unchanged.
    expect($pmPaper['checks'])->toBe([])
        ->and(zLines($pmPaper)['Plus shortage payments'])->toBe(2500)
        ->and($pmPaper['figures']['net_sales'])->toBe(-9000)
        ->and($pmPaper['figures']['expected'])->toBe($pm->expected_cash->centavos())
        ->and($pm->grand_total_before->centavos())->toBe($am->grand_total_after->centavos())
        ->and($pm->grand_total_after->centavos())->toBe($am->grand_total_after->centavos() - 9000)
        ->and($this->reports->zReport($am->fresh()))->toEqual($paper);

    // The day is the sum of its two shifts, agrees with the ledger, and closes.
    $daily = $this->reports->daily(Hotel::branch()->id, '2026-11-12');
    expect($daily['checks'])->toBe([])
        ->and($daily['total']['total'])->toBe($paper['figures']['net_sales'] + $pmPaper['figures']['net_sales'])
        ->and(array_column($daily['columns'], 'total'))->toBe([$paper['figures']['net_sales'], $pmPaper['figures']['net_sales']])
        ->and($daily['reconcile']['differences'])->toBe([]);

    $closing = app(CloseDay::class)->handle(Hotel::user('ramil.ortega'), Hotel::branch()->id, '2026-11-12');
    $day = $this->reports->dayZ(Hotel::branch()->id, '2026-11-12');
    expect($day['checks'])->toBe([])
        ->and($day['preview'])->toBeFalse()
        ->and($day['header']['number'])->toBe('D-0001')
        ->and($day['figures']['net_sales'])->toBe($closing->net_sales_amount->centavos())
        ->and($day['figures']['net_sales'])->toBe($daily['total']['total'])
        ->and($day['figures']['expected'])->toBe($am->expected_cash->centavos() + $pm->expected_cash->centavos())
        ->and($day['figures']['short'])->toBe(2500)->and($closing->short_amount->centavos())->toBe(2500)
        ->and($day['figures']['over'])->toBe(0)
        ->and($day['figures']['grand_total_after'])->toBe($pm->grand_total_after->centavos())
        ->and(Totals::int(ShiftSession::query()->findOrFail($am->id)->totals, 'drawer.other_cash'))->toBe(0);
});
