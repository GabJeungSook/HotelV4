<?php

declare(strict_types=1);

use App\Actions\Pos\CompleteSale;
use App\Actions\Stays\AddCharges;
use App\Actions\Stays\CheckOutGuest;
use App\Actions\Stays\TakePayment;
use App\Actions\Stays\VoidLine;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PosMode;
use App\Enums\ReasonKind;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Room;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Queries\StayQuery;
use App\Summaries\CheckSummaries;
use App\Summaries\RebuildSummaries;
use App\Summaries\SummaryDays;
use App\Support\Clock;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\Hotel;

/*
| TRD §10 "Dashboards and summaries": rebuild equals the ledgers for a scripted day · a row
| written during a rebuild is picked up next run · CheckSummaries flags a tampered summary.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-10 00:00:00'); // 8:00 AM Manila
});

/** A day with check-ins, a discount, charges, a walk-in sale, a partial payment, a void, a check-out and a short close. */
function scriptedDay(): ShiftSession
{
    $fd = Hotel::frontdesk();
    $shift = Hotel::openShift($fd);
    $a = Hotel::checkIn('205', 12, by: $fd, guest: 'Ana Reyes');
    $b = Hotel::checkIn('101', 6, by: $fd, guest: 'Ben Cruz', discount: Hotel::discount('Promo'));
    test()->travelClock(70);
    app(AddCharges::class)->handle($fd, $b, [Hotel::product('Bottled water 500 ml')->id => 2, Hotel::product('Chips')->id => 1]);
    app(CompleteSale::class)->handle($fd, Hotel::branch()->id, PosMode::WalkIn, [Hotel::product('Chips')->id => 2], (string) Str::uuid(), Money::ofPesos(200));
    app(TakePayment::class)->handle($fd, $b, PaymentMethod::Gcash, Money::ofPesos(25), referenceNo: '1234567890');
    $chips = FolioLine::query()->where('stay_id', $b->id)->where('kind', LineKind::FoodDrink)->where('description', 'like', '%Chips%')->firstOrFail();
    app(VoidLine::class)->handle($fd, $chips, Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount'), Hotel::supervisor(), Hotel::PIN, Hotel::device());
    test()->travelClock(200);
    $due = app(StayQuery::class)->balanceDue($a->id);
    app(CheckOutGuest::class)->handle($fd, $a, payWith: PaymentMethod::Cash, amountGiven: $due->isZero() ? null : $due);
    test()->travelClock(400);
    Hotel::closeAndHandOver(Hotel::user('mark.sumagaysay'), $fd, offset: -8000);

    return $shift->fresh();
}

it('rebuilds a scripted day equal to the ledgers', function (): void {
    $shift = scriptedDay();
    $branch = Hotel::branch()->id;
    $date = $shift->business_date->toDateString();
    $shiftIds = ShiftSession::query()->whereDate('business_date', $date)->pluck('id');

    app(RebuildSummaries::class)->run();

    $daily = DB::table('daily_summaries')->where('branch_id', $branch)->where('business_date', $date)->first();
    $lines = FolioLine::query()->whereIn('shift_session_id', $shiftIds)->get();
    $payments = Payment::query()->whereIn('shift_session_id', $shiftIds)->get();
    $sum = fn ($rows): int => (int) $rows->sum(fn ($r): int => $r->amount->centavos());

    expect((int) $daily->net_sales_amount)->toBe($sum($lines))
        ->and((int) $daily->gross_sales_amount - (int) $daily->discount_amount)->toBe((int) $daily->net_sales_amount)
        ->and((int) $daily->discount_amount)->toBe(-$sum($lines->where('kind', LineKind::Discount)))
        ->and((int) $daily->discount_amount)->toBeGreaterThan(0)
        ->and((int) $daily->collected_amount)->toBe($sum($payments->whereIn('kind', [PaymentKind::Payment, PaymentKind::DepositApplied])))
        ->and((int) $daily->checkins)->toBe(2)
        ->and((int) $daily->checkouts)->toBe(1)
        ->and((int) $daily->short_amount)->toBe(8000)
        ->and((int) $daily->over_amount)->toBe(0);

    // The hourly buckets, the room-type and product lines and the payment rows add up to the day.
    expect((int) DB::table('hourly_sales_summaries')->where('business_date', $date)->sum('net_sales_amount'))->toBe((int) $daily->net_sales_amount)
        ->and((int) DB::table('hourly_sales_summaries')->where('business_date', $date)->sum('checkins'))->toBe(2)
        ->and((int) DB::table('daily_report_lines')->where('business_date', $date)->where('section', 'room_type')->sum('amount') + (int) $daily->other_sales_amount)->toBe((int) $daily->net_sales_amount)
        ->and((int) DB::table('daily_payment_summaries')->where('business_date', $date)->sum('amount'))->toBe((int) $daily->collected_amount)
        ->and((int) DB::table('daily_payment_summaries')->where('business_date', $date)->where('method', 'gcash')->sum('amount'))->toBe(2500)
        ->and(DB::table('daily_report_lines')->where('business_date', $date)->where('section', 'stay_option')->pluck('quantity', 'label')->map(fn ($q): int => (int) $q)->all())->toBe(['12 hrs' => 1, '6 hrs' => 1]);

    // Hours are bucketed in Manila time: the check-ins were at 8 AM local.
    expect(DB::table('hourly_sales_summaries')->where('business_date', $date)->where('checkins', '>', 0)->pluck('hour')->all())->toBe([8]);

    expect(app(CheckSummaries::class)->day($branch, $date))->toBe([]);
});

it('picks up a row written during a rebuild on the next run', function (): void {
    $shift = Hotel::openShift();
    $stay = Hotel::checkIn('101', 6);
    $branch = Hotel::branch()->id;
    $date = $shift->business_date->toDateString();
    $days = app(SummaryDays::class);
    $rebuild = app(RebuildSummaries::class);

    $rebuild->run();
    $clean = fn (): bool => DB::table('summary_days')->where('branch_id', $branch)->where('business_date', $date)
        ->whereColumn('dirty_at', '>=', 'rebuilt_at')->doesntExist();

    // A run starts, then a payment is written before it finishes.
    $this->travelClock(5);
    $startedAt = app(Clock::class)->now();
    $this->travelClock(1);
    app(AddCharges::class)->handle(Hotel::frontdesk(), $stay, [Hotel::product('Chips')->id => 1]);
    $rebuild->day($branch, $date, $startedAt);

    expect($clean())->toBeFalse()
        ->and(collect($days->due([]))->contains([$branch, $date]))->toBeTrue();

    $this->travelClock(1);
    $rebuild->run();

    expect($clean())->toBeTrue()
        ->and((int) DB::table('daily_summaries')->where('business_date', $date)->value('net_sales_amount'))
        ->toBe((int) FolioLine::query()->sum('amount'));
});

it('logs an error when a summary row is changed by hand', function (): void {
    $shift = scriptedDay();
    $date = $shift->business_date->toDateString();
    app(RebuildSummaries::class)->run();
    expect(app(CheckSummaries::class)->run())->toBe([]);

    DB::table('daily_summaries')->where('business_date', $date)->update(['net_sales_amount' => DB::raw('net_sales_amount + 100')]);
    DB::table('daily_payment_summaries')->where('business_date', $date)->where('method', 'cash')->update(['amount' => DB::raw('amount - 500')]);

    Log::spy();
    $problems = app(CheckSummaries::class)->run();

    expect($problems)->toHaveCount(2)
        ->and($problems[0])->toContain('daily_payment_summaries')
        ->and($problems[1])->toContain('net_sales_amount');
    Log::shouldHaveReceived('error')->twice();
});

it('flags a summary more than 10 minutes older than the newest ledger row', function (): void {
    $shift = Hotel::openShift();
    Hotel::checkIn('101', 6);
    app(RebuildSummaries::class)->run();
    $this->travelClock(11);
    app(AddCharges::class)->handle(Hotel::frontdesk(), Stay::query()->firstOrFail(), [Hotel::product('Chips')->id => 1]);

    expect(app(CheckSummaries::class)->day(Hotel::branch()->id, $shift->business_date->toDateString()))
        ->toContain('summary is more than 10 minutes older than the newest ledger row');
});

it('rebuilds one busy branch-day within the 2 s budget', function (): void {
    $shift = Hotel::openShift();
    $rooms = Room::query()->where('status', 'available')->limit(30)->pluck('number');
    foreach ($rooms as $number) {
        $stay = Hotel::checkIn((string) $number, 6);
        app(AddCharges::class)->handle(Hotel::frontdesk(), $stay, [Hotel::product('Shampoo sachet')->id => 2]);
    }

    $start = microtime(true);
    app(RebuildSummaries::class)->day(Hotel::branch()->id, $shift->business_date->toDateString());

    expect(microtime(true) - $start)->toBeLessThan(2.0);
});
