<?php

declare(strict_types=1);

use App\Enums\PrintDocument;
use App\Http\Middleware\EnsureDeviceKey;
use App\Models\AuditLog;
use App\Models\FolioLine;
use App\Models\PrintLog;
use App\Models\ShiftSession;
use App\Services\ReportService;
use App\Support\Invariants;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoShiftSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Hotel;

/*
| Print logging of the check-in slip (8) and the receipt (18), counted on the Z-report under
| "Slip reprints"; invariant 7 (a day's shifts equal the day's ledger) and the CheckInvariants job.
*/

it('logs every print of the check-in slip and the receipt, and marks the second as a reprint', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 01:00:00');
    $shift = Hotel::openShift();
    $stay = Hotel::checkIn('205', 6);
    $this->actingAs(Hotel::frontdesk())->withUnencryptedCookie(EnsureDeviceKey::COOKIE, (string) Str::uuid());

    $this->postJson("/stays/{$stay->id}/print", ['document' => 'checkin_slip'])->assertOk()->assertJson(['reprint' => false]);
    $this->postJson("/stays/{$stay->id}/print", ['document' => 'checkin_slip'])->assertOk()->assertJson(['reprint' => true]);
    $this->postJson("/stays/{$stay->id}/print", ['document' => 'receipt'])->assertOk()->assertJson(['reprint' => false]);

    expect(PrintLog::query()->where('subject_id', $stay->id)->orderBy('id')->pluck('document')->all())->toBe([PrintDocument::CheckinSlip, PrintDocument::CheckinSlip, PrintDocument::Receipt])
        ->and(PrintLog::query()->pluck('shift_session_id')->unique()->all())->toBe([$shift->id]);

    $reprints = collect(collect(app(ReportService::class)->zReport($shift->fresh())['sections'])->firstWhere('key', 'exceptions')['lines'])->firstWhere('label', 'Slip reprints');
    expect($reprints['count'])->toBe(1);

    // Housekeepers and other branches' stays are not theirs to print.
    $this->actingAs(Hotel::user('dodong.lumayag'))->postJson("/stays/{$stay->id}/print")->assertForbidden();
});

it('invariant 7: a day’s shift totals equal the day read from the ledger', function (): void {
    $this->seed([DatabaseSeeder::class, DemoShiftSeeder::class]);
    expect(Invariants::businessDaysAgree())->toBe([]);

    $am = ShiftSession::query()->where('z_no', 629)->sole();
    $line = FolioLine::query()->where('shift_session_id', $am->id)->where('kind', 'room')->first();
    DB::table('folio_lines')->where('id', $line->id)->update(['amount' => $line->amount->centavos() + 100, 'unit_amount' => $line->unit_amount->centavos() + 100]);

    expect(Invariants::businessDaysAgree())->toContain('7. Branch '.Hotel::branch()->id.', 2026-11-11: day.net_sales is 1816000 in the shifts\' totals but 1816100 in the ledger.')
        ->and(Artisan::call('homi:check-invariants'))->toBe(1)
        ->and(AuditLog::query()->where('action', 'invariant.failed')->pluck('after_json')->pluck('message')->all())->toContain('7. Branch '.Hotel::branch()->id.', 2026-11-11: day.net_sales is 1816000 in the shifts\' totals but 1816100 in the ledger.');

    DB::table('folio_lines')->where('id', $line->id)->update(['amount' => $line->amount->centavos(), 'unit_amount' => $line->unit_amount->centavos()]);
    DB::table('audit_logs')->where('action', 'invariant.failed')->delete();
    expect(Artisan::call('homi:check-invariants'))->toBe(0)
        ->and(AuditLog::query()->where('action', 'invariant.failed')->count())->toBe(0);
});

it('schedules the nightly check at 09:00 and the invariants at 09:05, Manila time', function (): void {
    $events = collect(app(Schedule::class)->events())->keyBy(fn ($e) => (string) preg_replace('/^.*artisan"? /', '', (string) $e->command));

    expect($events['homi:reconcile-business-day']->expression)->toBe('0 9 * * *')
        ->and($events['homi:reconcile-business-day']->timezone)->toBe('Asia/Manila')
        ->and($events['homi:check-invariants']->expression)->toBe('5 9 * * *');
});
