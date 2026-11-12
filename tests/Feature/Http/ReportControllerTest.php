<?php

declare(strict_types=1);

use App\Enums\PrintDocument;
use App\Enums\PrintFormat;
use App\Models\Branch;
use App\Models\DayClosing;
use App\Models\PrintLog;
use App\Models\ShiftSession;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoShiftSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| ReportController (36–43, 37b, 37c, 38b, the shifts list and the exports): happy path, validation
| and who may see what. The figures themselves are tested in tests/Feature/Reports.
*/

beforeEach(function (): void {
    $this->seed([DatabaseSeeder::class, DemoShiftSeeder::class]);
    $this->freezeClock('2026-11-12 01:00:00'); // 9:00 AM Manila, 12 Nov: yesterday is 11 Nov
    $this->am = ShiftSession::query()->where('z_no', 629)->sole();
    $this->open = ShiftSession::query()->where('status', 'open')->sole();
    $this->backoffice = Hotel::user('grace.tolentino');
});

it('shows the report hub with the check of the previous business day', function (): void {
    $this->actingAs($this->backoffice)->get('/reports')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reports/Hub')
        ->where('check.date', '2026-11-11')
        ->where('check.checked', true)
        ->where('check.differences', [])
        ->where('latest_shift_id', $this->am->id));
});

it('lists the shifts with their Z numbers and frozen net sales', function (): void {
    $this->actingAs($this->backoffice)->get('/reports/shifts')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reports/Shifts')
        ->has('list.rows', 3)
        ->where('list.rows.0.status', 'open')->where('list.rows.0.net_sales', null)
        ->where('list.rows.1.z_no', 629)->where('list.rows.1.net_sales', 1816000)->where('list.rows.1.variance', -5000));
});

it('sends the menu link Z-report to the last closed shift', function (): void {
    $this->actingAs($this->backoffice)->get('/reports/z')->assertRedirect("/reports/shifts/{$this->am->id}/z");
});

it('shows the shift report (37) and the ledger rows behind a figure', function (): void {
    $this->actingAs($this->backoffice)->get("/reports/shifts/{$this->am->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reports/Shift')
        ->where('report.sales_total', 1816000)
        ->where('report.expected', 1319500)
        ->where('report.checks', [])
        ->missing('ledger'));

    $this->actingAs($this->backoffice)->get("/reports/shifts/{$this->am->id}?figure=expenses")->assertInertia(fn (Assert $page) => $page
        ->reloadOnly('ledger', fn (Assert $reload) => $reload->where('ledger.total', 65000)->has('ledger.rows', 3)));
});

it('shows the Z-report (37b) and each of its tabs', function (): void {
    $this->actingAs($this->backoffice)->get("/reports/shifts/{$this->am->id}/z")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reports/ZReport')
        ->where('tab', 'sales')
        ->where('paper.header.number', 'Z-0629')
        ->where('paper.figures.net_sales', 1816000)
        ->where('paper.figures.expected', 1319500)
        ->where('paper.figures.grand_total_after', 1150050000)
        ->where('paper.checks', [])
        ->where('rooms', null));

    $this->actingAs($this->backoffice)->get("/reports/shifts/{$this->am->id}/z?tab=rooms")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('rooms.gross.total', 1816000)->where('rooms.deposits.total', 460000)->has('rooms.charts', 3));

    foreach (['cleaning', 'housekeeping', 'stocks', 'expenses'] as $tab) {
        $this->actingAs($this->backoffice)->get("/reports/shifts/{$this->am->id}/z?tab={$tab}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('tab', $tab)->whereNot($tab, null));
    }
});

it('exports the Z-report as CSV and PDF and logs each export', function (): void {
    $csv = $this->actingAs($this->backoffice)->get("/reports/shifts/{$this->am->id}/z.csv");
    $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertDownload('z-0629-2026-11-11-AM.csv');
    $body = $csv->streamedContent();
    expect(str_starts_with($body, "\u{FEFF}Section,Line,Count,Amount"))->toBeTrue()
        ->and($body)->toContain('"Sales and cash","Net sales",,18160.00')
        ->and($body)->toContain('"Sales and cash","Expected in the drawer",,13195.00')
        ->and($body)->toContain('"Sales and cash",Short,,-50.00')
        ->and($body)->toContain('"Running grand total","After this shift",,11500500.00');

    $pdf = $this->actingAs($this->backoffice)->get("/reports/shifts/{$this->am->id}/z.pdf");
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $logs = PrintLog::query()->where('document', PrintDocument::ZReport)->where('subject_id', $this->am->id)->orderBy('id')->get();
    expect($logs->pluck('format')->all())->toBe([PrintFormat::Print, PrintFormat::Csv, PrintFormat::Pdf])
        ->and($logs->last()->is_reprint)->toBeTrue()
        ->and($logs->last()->user_id)->toBe($this->backoffice->id);
});

it('refuses to export or print the preview of an open shift', function (): void {
    $this->actingAs($this->backoffice)->get("/reports/shifts/{$this->open->id}/z")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('paper.preview', true)->where('paper.header.number', null));

    $this->actingAs($this->backoffice)->from("/reports/shifts/{$this->open->id}/z")->get("/reports/shifts/{$this->open->id}/z.pdf")
        ->assertRedirect("/reports/shifts/{$this->open->id}/z");
    expect(session('inertia.flash_data.error.message'))->toBe('A preview cannot be exported. Close the shift first.');

    $this->actingAs($this->backoffice)->get("/reports/shifts/{$this->open->id}/z.csv")->assertRedirect();
    $this->actingAs($this->backoffice)->get("/reports/shifts/{$this->open->id}?export=csv")->assertRedirect();
    $this->actingAs($this->backoffice)->postJson("/reports/shifts/{$this->open->id}/print", ['document' => 'z_report'])->assertStatus(422);
    expect(PrintLog::query()->where('subject_id', $this->open->id)->count())->toBe(0);
});

it('logs a print of the Z-report and says whether it is a reprint', function (): void {
    $this->actingAs($this->backoffice)->postJson("/reports/shifts/{$this->am->id}/print", ['document' => 'z_report'])->assertOk()->assertJson(['reprint' => true]);
    $this->actingAs($this->backoffice)->postJson("/reports/shifts/{$this->am->id}/print", ['document' => 'report'])->assertOk()->assertJson(['reprint' => false]);
});

it('shows daily sales for a day, a week and a month, and exports them', function (): void {
    $this->actingAs($this->backoffice)->get('/reports/daily?date=2026-11-11')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reports/Daily')
        ->has('report.columns', 2)
        ->where('report.columns.0.total', 1816000)
        ->where('report.columns.1.open', true)
        ->where('report.checks', [])
        ->where('can_close', true));

    $this->actingAs($this->backoffice)->get('/reports/daily?date=2026-11-11&range=week')->assertOk()->assertInertia(fn (Assert $page) => $page->has('report.columns', 7)->where('report.from', '2026-11-09'));
    $this->actingAs($this->backoffice)->get('/reports/daily?date=2026-11-11&range=month')->assertOk()->assertInertia(fn (Assert $page) => $page->has('report.columns', 6)->where('report.to', '2026-11-30'));

    $csv = $this->actingAs($this->backoffice)->get('/reports/daily?date=2026-11-11&export=csv');
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Room,14350.00');
});

it('shows close the day and closes it for back office, not for a supervisor', function (): void {
    $this->actingAs($this->backoffice)->get('/reports/daily/close?date=2026-11-10')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reports/CloseDay')
        ->where('day.reason', null)
        ->where('day.day_z', 'D-0001')
        ->where('day.closed', null)
        ->where('can_close', true));

    $this->actingAs(Hotel::supervisor())->get('/reports/daily/close?date=2026-11-10')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can_close', false));
    $this->actingAs(Hotel::supervisor())->post('/reports/daily/close', ['date' => '2026-11-10'])->assertForbidden();
    expect(DayClosing::query()->count())->toBe(0);

    $this->actingAs($this->backoffice)->post('/reports/daily/close', ['date' => '2026-11-10'])->assertRedirect('/reports/daily/close?date=2026-11-10');
    expect(DayClosing::query()->sole()->day_z_no)->toBe(1)
        ->and(session('inertia.flash_data.toast.message'))->toBe('D-0001 · the day is closed.');

    $this->actingAs($this->backoffice)->get('/reports/daily/close?date=2026-11-10')->assertInertia(fn (Assert $page) => $page
        ->where('day.closed.day_z_no', 1)->where('day.paper.header.number', 'D-0001')->where('day.paper.checks', []));
    $this->actingAs($this->backoffice)->get('/reports/daily/2026-11-10/z.csv')->assertOk();
    $this->actingAs($this->backoffice)->get('/reports/daily/2026-11-10/z.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->backoffice)->postJson('/reports/daily/2026-11-10/print')->assertOk()->assertJson(['reprint' => true]);
});

it('refuses to close a day with an open shift and keeps the page', function (): void {
    $this->actingAs($this->backoffice)->get('/reports/daily/close?date=2026-11-11')->assertInertia(fn (Assert $page) => $page->where('day.reason', 'A shift of this date is still open. Close it first.'));

    $this->actingAs($this->backoffice)->from('/reports/daily/close?date=2026-11-11')->post('/reports/daily/close', ['date' => '2026-11-11'])
        ->assertRedirect('/reports/daily/close?date=2026-11-11');
    expect(session('inertia.flash_data.error.message'))->toBe('A shift of this date is still open. Close it first.')
        ->and(DayClosing::query()->count())->toBe(0);
});

it('shows occupancy, housekeeping, inventory and the audit trail, each with a CSV', function (): void {
    $this->actingAs($this->backoffice)->get('/reports/occupancy?date=2026-11-11')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reports/Occupancy')->where('report.start', 19)->where('report.in', 27)->where('report.out', 23)->where('report.end', 23));
    $this->actingAs($this->backoffice)->get('/reports/housekeeping?date=2026-11-11')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Reports/Housekeeping')->has('shifts', 2));
    $this->actingAs($this->backoffice)->get("/reports/housekeeping?shift={$this->am->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('report.shift_id', $this->am->id));
    $this->actingAs($this->backoffice)->get('/reports/inventory?date=2026-11-11')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Reports/Inventory')->where('report.checks', []));
    $this->actingAs($this->backoffice)->get('/reports/audit?date=2026-11-11')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Reports/Audit')
        ->where('report.rows', fn ($rows) => collect($rows)->pluck('type')->contains('voids') && collect($rows)->pluck('type')->contains('transfers') && collect($rows)->pluck('type')->contains('cancels')));
    $this->actingAs($this->backoffice)->get('/reports/audit?date=2026-11-11&type=transfers')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('report.rows', fn ($rows) => collect($rows)->every(fn ($r) => $r['type'] === 'transfers') && count($rows) === 1));

    foreach (['occupancy', 'housekeeping', 'inventory', 'audit'] as $report) {
        $this->actingAs($this->backoffice)->get("/reports/{$report}?date=2026-11-11&export=csv")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    expect(PrintLog::query()->where('document', PrintDocument::Report)->where('format', PrintFormat::Csv)->count())->toBe(4);
});

it('validates the query string', function (): void {
    foreach (['/reports/daily?date=11-11-2026', '/reports/daily?range=year', "/reports/shifts/{$this->am->id}/z?tab=floors", '/reports/audit?type=deletes', "/reports/shifts/{$this->am->id}?export=xls", "/reports/shifts/{$this->am->id}?figure=drop%20table"] as $url) {
        $this->actingAs($this->backoffice)->from('/reports')->get($url)->assertRedirect('/reports')->assertSessionHasErrors();
    }

    $this->actingAs($this->backoffice)->post('/reports/daily/close', ['date' => 'yesterday'])->assertSessionHasErrors('date');
    $this->actingAs($this->backoffice)->get('/reports/daily/nope/z.pdf')->assertNotFound();
});

it('lets admin, owner and supervisor read the reports, and refuses front desk, housekeeper and kiosk', function (): void {
    foreach (['ramil.ortega', 'ramon.alvarez', 'carol.villanueva'] as $username) {
        $this->actingAs(Hotel::user($username))->get("/reports/shifts/{$this->am->id}/z")->assertOk();
        $this->actingAs(Hotel::user($username))->get('/reports')->assertOk();
    }

    foreach (['jessa.delacruz', 'dodong.lumayag', 'kiosk-gensan-1'] as $username) {
        $this->actingAs(Hotel::user($username))->get('/reports')->assertForbidden();
        $this->actingAs(Hotel::user($username))->get("/reports/shifts/{$this->am->id}/z")->assertForbidden();
        $this->actingAs(Hotel::user($username))->get("/reports/shifts/{$this->am->id}/z.csv")->assertForbidden();
    }
});

it('shows the open shift preview to a supervisor only while they have recorded no money on it', function (): void {
    $carol = Hotel::supervisor();
    $this->actingAs($carol)->get("/reports/shifts/{$this->open->id}/z")->assertOk();

    // Carol records a payment on the open shift: from now on the preview (expected cash) is not hers to see.
    $this->travelClock(10);
    Hotel::checkIn('207', 6, by: $carol, guest: 'Test Guest');
    $this->actingAs($carol)->get("/reports/shifts/{$this->open->id}/z")->assertForbidden();
    $this->actingAs($carol)->get("/reports/shifts/{$this->open->id}")->assertForbidden();
    $this->actingAs($carol)->get("/reports/shifts/{$this->am->id}/z")->assertOk();
    $this->actingAs($this->backoffice)->get("/reports/shifts/{$this->open->id}/z")->assertOk();
});

it('keeps a branch admin to their own branch; the owner may pick any', function (): void {
    $other = Branch::factory()->create();
    $shift = ShiftSession::factory()->create(['branch_id' => $other->id]);

    $this->actingAs(Hotel::user('ramil.ortega'))->get("/reports/shifts/{$shift->id}/z")->assertNotFound();
    $this->actingAs(Hotel::user('ramon.alvarez'))->get("/reports/shifts/{$shift->id}/z")->assertOk();
    $this->actingAs(Hotel::user('ramon.alvarez'))->get("/reports/daily?date=2026-11-11&branch={$other->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('report.columns', 1)->where('report.columns.0.shift_id', $shift->id));
    $this->actingAs(Hotel::user('ramil.ortega'))->get("/reports/daily?date=2026-11-11&branch={$other->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->has('report.columns', 2));

    $shift->delete();
});

it('puts the reports in the back office menu', function (): void {
    $this->actingAs($this->backoffice)->get('/reports')->assertInertia(fn (Assert $page) => $page
        ->where('nav', fn ($nav) => collect($nav)->firstWhere('heading', 'Reports')['items'] !== null
            && collect(collect($nav)->firstWhere('heading', 'Reports')['items'])->pluck('label')->all() === ['Reports', 'Shift reports', 'Z-report', 'Daily sales', 'Occupancy', 'Housekeeping', 'Inventory', 'Audit trail']));
});
