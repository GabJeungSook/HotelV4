<?php

declare(strict_types=1);

use App\Actions\Pos\CompleteSale;
use App\Actions\Pos\ReceiveStock;
use App\Actions\Reports\CloseDay;
use App\Actions\Stays\AddCharges;
use App\Actions\Stays\CheckOutGuest;
use App\Enums\CashMovementKind;
use App\Enums\PaymentMethod;
use App\Enums\PosMode;
use App\Exceptions\DayNotClosable;
use App\Models\AuditLog;
use App\Models\DayClosing;
use App\Models\FolioLine;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Queries\StayQuery;
use App\Services\ReportService;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Hotel;

/*
| ROADMAP Phase 8 "Done when": for a generated month of data, every day equals the sum of its
| shifts, every day can be closed in date order, and the reconcile job reports no mismatch;
| changing one ledger row by hand makes the job flag that day and CloseDay refuse it.
|
| November 2026 is generated through the real actions: two shifts a day, each with check-ins
| (some hourly, some overnight forwarded to the next shift), charges, a walk-in sale, check-outs
| with payment, an expense, and a handover to the other front desk.
*/

it('generates a month in which every day is the sum of its shifts, closes in order, and flags a tampered row', function (): void {
    $this->seed(DatabaseSeeder::class);
    Hotel::branch()->update(['housekeeping_enabled' => false]); // rooms come straight back for sale
    $this->freezeClock('2026-11-01 00:00:00'); // 8:00 AM Manila, AM 1 Nov
    $people = [Hotel::frontdesk(), Hotel::user('mark.sumagaysay')];
    $rooms = ['101', '102', '103', '104', '106', '107', '108', '109', '110', '111'];
    $chips = Hotel::product('Chips');
    $water = Hotel::product('Bottled water 500 ml');
    Hotel::openShift($people[0], 500000);

    for ($n = 0; $n < 60; $n++) {
        $by = $people[$n % 2];

        // Guests still in house leave (their charges paid in this shift).
        foreach (Stay::query()->where('state', 'checked_in')->get() as $stay) {
            $due = app(StayQuery::class)->balanceDue($stay->id);
            app(CheckOutGuest::class)->handle($by, $stay, payWith: PaymentMethod::Cash, amountGiven: $due->isZero() ? null : $due);
        }

        $a = Hotel::checkIn($rooms[$n % 10], 6, by: $by, guest: "Guest {$n}A");
        $b = Hotel::checkIn($rooms[($n + 5) % 10], 12, by: $by, guest: "Guest {$n}B");
        app(AddCharges::class)->handle($by, $b, [$water->id => 1 + $n % 3]); // left unpaid, forwarded
        app(CompleteSale::class)->handle($by, Hotel::branch()->id, PosMode::WalkIn, [$chips->id => 1], (string) Str::uuid(), Money::ofPesos(50));
        $this->travelClock(240);
        $due = app(StayQuery::class)->balanceDue($a->id);
        app(CheckOutGuest::class)->handle($by, $a, payWith: PaymentMethod::Cash, amountGiven: $due->isZero() ? null : $due);
        if ($n % 4 === 0) {
            Hotel::movement(CashMovementKind::Expense, 5000 + 100 * $n, $by);
        }
        foreach ([$chips, $water] as $product) {
            if ($product->fresh()->stock_on_hand < 6) {
                app(ReceiveStock::class)->handle($by, Hotel::branch()->id, $product->id, 24, 'Restock');
            }
        }

        $this->travelClock(480);
        Hotel::closeAndHandOver($people[($n + 1) % 2], $by, offset: $n % 7 === 0 ? -1000 : ($n % 11 === 0 ? 500 : 0));
    }

    $reports = app(ReportService::class);
    $branch = Hotel::branch()->id;
    $close = app(CloseDay::class);
    $admin = Hotel::user('ramil.ortega');
    $days = array_map(fn (int $d): string => sprintf('2026-11-%02d', $d), range(1, 30));

    // Every day is the sum of its two shifts and of its ledger rows, and the check finds nothing.
    foreach ($days as $date) {
        $daily = $reports->daily($branch, $date);
        $ids = ShiftSession::query()->whereDate('business_date', $date)->pluck('id');

        expect($daily['columns'])->toHaveCount(2, $date)
            ->and($daily['total']['total'])->toBe(array_sum(array_column($daily['columns'], 'total')), $date)
            ->and($daily['total']['total'])->toBe((int) FolioLine::query()->whereIn('shift_session_id', $ids)->sum('amount'), $date)
            ->and($daily['checks'])->toBe([], $date)
            ->and($reports->reconcileDay($branch, $date))->toBe([], $date);
    }

    // The nightly job passes on each of the first days; each closes in date order.
    foreach (array_slice($days, 0, 14) as $date) {
        expect(Artisan::call('homi:reconcile-business-day', ['--date' => $date]))->toBe(0, $date);
        $day = $close->handle($admin, $branch, $date);
        $paper = $reports->dayZ($branch, $date);
        expect($paper['checks'])->toBe([], $date)
            ->and($paper['figures']['net_sales'])->toBe($day->net_sales_amount->centavos(), $date);
    }

    // Someone changes a room line of 15 Nov by hand: the job flags that day and CloseDay refuses it.
    $line = FolioLine::query()->whereIn('shift_session_id', ShiftSession::query()->whereDate('business_date', '2026-11-15')->pluck('id'))->where('kind', 'room')->first();
    DB::table('folio_lines')->where('id', $line->id)->update(['amount' => $line->amount->centavos() + 5000, 'unit_amount' => $line->unit_amount->centavos() + 5000]);

    expect(Artisan::call('homi:reconcile-business-day', ['--date' => '2026-11-15']))->toBe(1)
        ->and(AuditLog::query()->where('action', 'reconcile.mismatch')->sole()->after_json['date'])->toBe('2026-11-15')
        ->and(fn () => $close->handle($admin, $branch, '2026-11-15'))->toThrow(DayNotClosable::class)
        ->and(DayClosing::query()->count())->toBe(14)
        ->and(DayClosing::query()->orderBy('business_date')->pluck('day_z_no')->all())->toBe(range(1, 14));

    // Put the row back and clear the flag so the invariants hold after the test.
    DB::table('folio_lines')->where('id', $line->id)->update(['amount' => $line->amount->centavos(), 'unit_amount' => $line->unit_amount->centavos()]);
    DB::table('audit_logs')->where('action', 'reconcile.mismatch')->delete();

    // The rest of the month closes in order, and the grand total chains through every day.
    foreach (array_slice($days, 14) as $date) {
        $close->handle($admin, $branch, $date);
    }
    $closings = DayClosing::query()->orderBy('business_date')->get();
    expect($closings)->toHaveCount(30);
    foreach ($closings as $i => $day) {
        if ($i > 0) {
            expect($day->grand_total_before->centavos())->toBe($closings[$i - 1]->grand_total_after->centavos());
        }
    }

    // A month of daily sales is the sum of its days.
    $month = $reports->daily($branch, '2026-11-15', 'month');
    expect($month['total']['total'])->toBe((int) $closings->sum(fn (DayClosing $d): int => $d->net_sales_amount->centavos()) + $reports->daily($branch, '2026-12-01')['total']['total']);
})->group('slow');
