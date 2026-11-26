<?php

declare(strict_types=1);

use App\Actions\Reports\CloseDay;
use App\Actions\Reports\LogPrint;
use App\Actions\Stays\AddCharges;
use App\Actions\Stays\VoidLine;
use App\Enums\PrintDocument;
use App\Enums\PrintFormat;
use App\Enums\ReasonKind;
use App\Exceptions\DayNotClosable;
use App\Exceptions\PreviewNotExportable;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DayClosing;
use App\Models\FolioLine;
use App\Models\PrintLog;
use App\Models\ShiftSession;
use App\Services\CurrentShift;
use App\Services\Reports\ZPaper;
use App\Services\ReportService;
use App\Services\ZReportExport;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoShiftSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Hotel;

/*
| TRD §10 "Z-report and day close". The concurrent-close test is in
| tests/Concurrency/ConcurrentCloseTest.php (it needs two real processes).
*/

beforeEach(function (): void {
    $this->reports = app(ReportService::class);
});

/** Run $n shifts through check-in and a handover from 12 Nov 8 AM on; returns them oldest first. */
function runShifts(int $n): array
{
    test()->freezeClock('2026-11-12 00:00:00');
    $people = [Hotel::frontdesk(), Hotel::user('mark.sumagaysay')];
    Hotel::openShift($people[0]);
    $rooms = ['205', '101', '102', '103', '104', '106', '107', '108'];
    $shifts = [];

    for ($i = 0; $i < $n; $i++) {
        $by = $people[$i % 2];
        Hotel::checkIn($rooms[$i], 6, by: $by, guest: "Guest {$i}");
        test()->travelClock(720);
        $shifts[] = app(CurrentShift::class)->find(Hotel::branch()->id)?->id;
        Hotel::closeAndHandOver($people[($i + 1) % 2], $by);
    }

    return ShiftSession::query()->whereIn('id', $shifts)->orderBy('id')->get()->all();
}

it('Z numbers are sequential per branch with no gaps or repeats', function (): void {
    $this->seed(DatabaseSeeder::class);
    $first = Hotel::branch()->next_z_number;
    $shifts = runShifts(4);

    expect(array_map(fn (ShiftSession $s): int => $s->z_no, $shifts))->toBe([$first, $first + 1, $first + 2, $first + 3])
        ->and(Hotel::branch()->next_z_number)->toBe($first + 4)
        ->and(ShiftSession::query()->whereNotNull('z_no')->count())->toBe(ShiftSession::query()->distinct()->count('z_no'));
});

it('grand total chains across shifts', function (): void {
    $this->seed(DatabaseSeeder::class);
    $shifts = runShifts(3);

    expect($shifts[0]->grand_total_before->centavos())->toBe(0);
    foreach ($shifts as $n => $shift) {
        expect($shift->grand_total_after->centavos())->toBe($shift->grand_total_before->centavos() + $shift->totals['sales']['total']);

        if ($n > 0) {
            expect($shift->grand_total_before->centavos())->toBe($shifts[$n - 1]->grand_total_after->centavos());
        }
    }
});

it('every bold line of the sales-and-cash column equals its arithmetic', function (): void {
    $this->seed([DatabaseSeeder::class, DemoShiftSeeder::class]);
    $paper = $this->reports->zReport(ShiftSession::query()->where('z_no', 629)->sole());
    $lines = collect($paper['sections'])->firstWhere('key', 'sales_cash')['lines'];
    $running = 0;
    $bold = [];

    foreach ($lines as $line) {
        if ($line['label'] === 'Counted') {
            break;
        }

        if ($line['style'] === 'equals') {
            expect($line['amount'])->toBe($running, $line['label']);
            $bold[] = $line['label'];
        } else {
            $running += $line['amount'];
        }
    }

    expect($bold)->toBe(['Net sales', 'Collected', 'Cash collected', 'Cash from this shift', 'Expected in the drawer'])
        ->and($paper['checks'])->toBe([]);
});

it('exceptions list has all fixed rows at zero', function (): void {
    $this->seed(DatabaseSeeder::class);
    [$shift] = runShifts(1);
    $exceptions = collect($this->reports->zReport($shift)['sections'])->firstWhere('key', 'exceptions')['lines'];

    expect(array_map(fn (array $l): array => [$l['label'], $l['count'], $l['amount']], $exceptions))->toBe([
        ['Cancelled stays', 0, 0],
        ['Voided lines', 0, 0],
        ['Cash refunds', 0, 0],
        ['Room transfers', 0, 0],
        ['Forfeited deposits', 0, 0],
        ['Voided cash entries', 0, 0],
        ['Slip reprints', 0, null],
    ]);
});

it('preview has no Z number and cannot be exported', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 00:00:00');
    $shift = Hotel::openShift();
    Hotel::checkIn('205', 6);
    $paper = $this->reports->zReport($shift);

    expect($paper['preview'])->toBeTrue()
        ->and($paper['header']['number'])->toBeNull()
        ->and($paper['z_no'])->toBeNull()
        ->and($paper['checks'])->toBe([]);

    $export = app(ZReportExport::class);
    expect(fn () => $export->pdf($paper, Hotel::supervisor(), Hotel::branch()->id, PrintDocument::ZReport, $shift->id, 'z.pdf'))->toThrow(PreviewNotExportable::class)
        ->and(fn () => $export->csv($paper, Hotel::supervisor(), Hotel::branch()->id, PrintDocument::ZReport, $shift->id, 'z.csv'))->toThrow(PreviewNotExportable::class)
        ->and(PrintLog::query()->count())->toBe(0);
});

it('a later void changes the later shift’s paper and grand total, not the earlier one', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 00:00:00');
    Hotel::openShift();
    $stay = Hotel::checkIn('205', 12);
    app(AddCharges::class)->handle(Hotel::frontdesk(), $stay, [Hotel::product('Chips')->id => 1]);
    $this->travelClock(720);
    $mark = Hotel::user('mark.sumagaysay');
    Hotel::closeAndHandOver($mark);
    $am = ShiftSession::query()->whereNotNull('z_no')->sole();
    $before = $this->reports->zReport($am);

    $chips = FolioLine::query()->where('stay_id', $stay->id)->where('description', 'Chips')->sole();
    app(VoidLine::class)->handle($mark, $chips, Hotel::reason(ReasonKind::VoidLine, 'Typed wrong amount'), Hotel::supervisor(), Hotel::PIN, Hotel::device());
    $this->travelClock(720);
    Hotel::closeAndHandOver(Hotel::frontdesk(), $mark);
    $pm = ShiftSession::query()->where('id', '>', $am->id)->whereNotNull('z_no')->sole();

    expect($this->reports->zReport($am->fresh()))->toEqual($before)
        ->and($am->fresh()->grand_total_after->centavos())->toBe($before['figures']['grand_total_after'])
        ->and($this->reports->zReport($pm)['figures']['net_sales'])->toBe(-3500)
        ->and($pm->grand_total_after->centavos())->toBe($am->grand_total_after->centavos() - 3500)
        ->and(collect($this->reports->zReport($pm)['sections'])->firstWhere('key', 'exceptions')['lines'][1])->toMatchArray(['label' => 'Voided lines', 'count' => 1, 'amount' => 3500]);
});

it('day close refused while a shift is open, out of order, or after a reconcile mismatch', function (): void {
    $this->seed(DatabaseSeeder::class);
    runShifts(4); // AM and PM of 12 Nov, AM and PM of 13 Nov; AM 14 Nov is open
    $close = app(CloseDay::class);
    $admin = Hotel::user('ramil.ortega');
    $branch = Hotel::branch()->id;

    expect(fn () => $close->handle($admin, $branch, '2026-11-14'))->toThrow(DayNotClosable::class, 'A shift of this date is still open. Close it first.')
        ->and(fn () => $close->handle($admin, $branch, '2026-11-13'))->toThrow(DayNotClosable::class, 'Close Thu 12 Nov first. Days are closed in date order.')
        ->and(DayClosing::query()->count())->toBe(0);

    // A tampered ledger row on 12 Nov: the check flags it and the day is refused.
    $shift = ShiftSession::query()->whereDate('business_date', '2026-11-12')->orderBy('id')->first();
    $line = FolioLine::query()->where('shift_session_id', $shift->id)->where('kind', 'room')->first();
    DB::table('folio_lines')->where('id', $line->id)->update(['amount' => 1, 'unit_amount' => 1]);
    expect(fn () => $close->handle($admin, $branch, '2026-11-12'))->toThrow(DayNotClosable::class, 'The check found figures that differ from the ledger for this date.');

    // The nightly job's stored mismatch keeps refusing it even after the row is put back.
    $this->freezeClock('2026-11-13 01:00:00');
    Artisan::call('homi:reconcile-business-day');
    DB::table('folio_lines')->where('id', $line->id)->update(['amount' => $line->amount->centavos(), 'unit_amount' => $line->unit_amount->centavos()]);
    expect(fn () => $close->handle($admin, $branch, '2026-11-12'))->toThrow(DayNotClosable::class)
        ->and(DayClosing::query()->count())->toBe(0)
        ->and(Branch::query()->find($branch)->next_day_z_number)->toBe(1);

    AuditLog::query()->where('action', 'reconcile.mismatch')->get()->each(fn (AuditLog $l) => DB::table('audit_logs')->where('id', $l->id)->delete());

    // In date order, with everything closed and agreeing: D-0001 then D-0002, chained.
    $d12 = $close->handle($admin, $branch, '2026-11-12');
    $d13 = $close->handle($admin, $branch, '2026-11-13');
    $shifts12 = ShiftSession::query()->whereDate('business_date', '2026-11-12')->orderBy('z_no')->get();

    expect([$d12->day_z_no, $d13->day_z_no])->toBe([1, 2])
        ->and($d12->grand_total_before->centavos())->toBe($shifts12->first()->grand_total_before->centavos())
        ->and($d12->grand_total_after->centavos())->toBe($shifts12->last()->grand_total_after->centavos())
        ->and($d12->net_sales_amount->centavos())->toBe($shifts12->sum(fn ($s): int => $s->totals['sales']['total']))
        ->and([$d12->shift_z_from, $d12->shift_z_to])->toBe([$shifts12->first()->z_no, $shifts12->last()->z_no])
        ->and($d13->grand_total_before->centavos())->toBe($d12->grand_total_after->centavos())
        ->and($close->handle($admin, $branch, '2026-11-12')->id)->toBe($d12->id)
        ->and(DayClosing::query()->count())->toBe(2);
});

it('PDF and CSV carry the same figures as the screen', function (): void {
    $this->seed([DatabaseSeeder::class, DemoShiftSeeder::class]);
    $am = ShiftSession::query()->where('z_no', 629)->sole();
    $paper = $this->reports->zReport($am);
    $export = app(ZReportExport::class);

    $csv = TestResponse::fromBaseResponse($export->csv($paper, Hotel::supervisor(), Hotel::branch()->id, PrintDocument::ZReport, $am->id, 'z.csv'));
    $body = $csv->streamedContent();
    expect(str_starts_with($body, "\u{FEFF}"))->toBeTrue();
    $rows = array_map(fn (string $l): array => str_getcsv($l, ',', '"', ''), array_filter(explode("\n", substr($body, 3))));
    expect(array_values($rows))->toBe(ZPaper::csvRows($paper));

    // Every amount on the paper is in the CSV.
    foreach ($paper['sections'] as $section) {
        foreach ($section['lines'] as $line) {
            if (isset($line['amount']) && ! isset($line['cells'])) {
                expect(collect($rows)->contains(fn (array $r): bool => $r[1] === $line['label'] && $r[3] === ZPaper::csvAmount($line['amount'])))->toBeTrue($line['label']);
            }
        }
    }

    // The PDF is rendered from the same array: its HTML carries every figure.
    $html = view('reports.zreport', ['paper' => $paper, 'logo' => null, 'product' => 'Homi'])->render();
    foreach (['Z-0629', '18,460.00', '−200.00', '18,160.00', '18,045.00', '8,195.00', '13,195.00', '13,145.00', '−50.00', '11,482,340.00', '11,500,500.00', 'Jessa Mae D.', 'Slip reprints', 'Stays DGS-26-004112 to 004138'] as $figure) {
        expect($html)->toContain($figure);
    }

    $pdf = TestResponse::fromBaseResponse($export->pdf($paper, Hotel::supervisor(), Hotel::branch()->id, PrintDocument::ZReport, $am->id, 'z.pdf'));
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $pdf->getContent(), 0, 5))->toBe('%PDF-');
});

it('every print is logged and reprints are marked', function (): void {
    $this->seed([DatabaseSeeder::class, DemoShiftSeeder::class]);
    $am = ShiftSession::query()->where('z_no', 629)->sole();
    $log = app(LogPrint::class);
    $before = PrintLog::query()->count();

    $first = $log->handle(Hotel::supervisor(), Hotel::branch()->id, PrintDocument::CheckinSlip, 999999);
    $second = $log->handle(Hotel::supervisor(), Hotel::branch()->id, PrintDocument::CheckinSlip, 999999);
    $z = $log->handle(Hotel::supervisor(), Hotel::branch()->id, PrintDocument::ZReport, $am->id, PrintFormat::Pdf);

    expect(PrintLog::query()->count())->toBe($before + 3)
        ->and($first->is_reprint)->toBeFalse()
        ->and($second->is_reprint)->toBeTrue()
        ->and($z->is_reprint)->toBeTrue() // the demo shift's Z was printed at 8:06 PM
        ->and($z->format)->toBe(PrintFormat::Pdf)
        ->and($second->shift_session_id)->toBe(ShiftSession::query()->where('status', 'open')->value('id'));

    // The open shift's Z preview counts the slip reprinted on it, not the re-export of a report.
    $open = ShiftSession::query()->where('status', 'open')->sole();
    $exceptions = collect($this->reports->zReport($open)['sections'])->firstWhere('key', 'exceptions')['lines'];
    expect(collect($exceptions)->firstWhere('label', 'Slip reprints')['count'])->toBe(1);
});

it('adds the VAT block only when the branch has VAT switched on', function (): void {
    $this->seed(DatabaseSeeder::class);
    Hotel::branch()->update(['vat_enabled' => true, 'vat_rate_percent' => 12, 'tin' => '123-456-789-000', 'registered_name' => 'Dalisay Hospitality Inc.']);
    $this->freezeClock('2026-11-12 00:00:00');
    Hotel::openShift();
    Hotel::checkIn('205', 12); // Deluxe 12 h ₱650, VATable
    Hotel::checkIn('101', 6, discount: Hotel::discount('Senior citizen'), guest: 'Lola Ramos'); // ₱350 − 20% = ₱280, exempt
    $this->travelClock(720);
    Hotel::closeAndHandOver(Hotel::user('mark.sumagaysay'));
    $am = ShiftSession::query()->whereNotNull('z_no')->sole();
    $paper = $this->reports->zReport($am);
    $vat = collect(collect($paper['sections'])->firstWhere('key', 'vat')['lines'])->pluck('amount', 'label');

    expect($am->totals['vat'])->toEqual(['mode' => 'percent', 'rate' => 12, 'amount' => 0, 'vatable' => 58036, 'vat' => 6964, 'exempt' => 28000])
        ->and($vat->all())->toBe(['VATable sales' => 58036, 'VAT 12%' => 6964, 'VAT-exempt sales' => 28000])
        ->and($vat->sum())->toBe($paper['figures']['net_sales'])
        ->and($paper['header']['tin'])->toBe('123-456-789-000')
        ->and($paper['header']['registered_name'])->toBe('Dalisay Hospitality Inc.')
        ->and($paper['checks'])->toBe([]);

    // With VAT off no VAT figure exists anywhere.
    Hotel::branch()->update(['vat_enabled' => false]);
    $this->travelClock(720);
    Hotel::closeAndHandOver(Hotel::frontdesk(), Hotel::user('mark.sumagaysay'));
    $pm = ShiftSession::query()->whereNotNull('z_no')->orderByDesc('z_no')->first();
    $pmPaper = $this->reports->zReport($pm);
    expect($pm->totals['vat'])->toBeNull()
        ->and(collect($pmPaper['sections'])->pluck('key'))->not->toContain('vat')
        ->and($pmPaper['header']['tin'])->toBeNull();
});
