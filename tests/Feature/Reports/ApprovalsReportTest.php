<?php

declare(strict_types=1);

use App\Enums\ApprovalAction;
use App\Models\Approval;
use App\Services\ReportService;
use App\Support\Clock;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Approvals & overrides report (new): every supervisor-approved action over a range, with who asked,
| who approved and why. Read-only through ReportService::approvals (the approvals table).
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 10:00:00');
    Hotel::openShift();
    $this->date = app(Clock::class)->localDate();

    $fd = Hotel::frontdesk();
    $sup = Hotel::supervisor();
    $now = app(Clock::class)->now();
    foreach ([
        [ApprovalAction::VoidLine, 'Wrong charge'],
        [ApprovalAction::VoidLine, 'Duplicate'],
        [ApprovalAction::CancelStay, 'Guest left'],
    ] as [$action, $reason]) {
        Approval::query()->create([
            'branch_id' => Hotel::branch()->id,
            'action' => $action,
            'subject_type' => 'stay',
            'subject_id' => 1,
            'requested_by' => $fd->id,
            'approved_by' => $sup->id,
            'reason' => $reason,
            'summary' => $action->label(),
            'created_at' => $now,
        ]);
    }

    $this->travelClock(30);
});

it('lists approvals with per-kind counts and who approved', function (): void {
    $report = app(ReportService::class)->approvals(Hotel::branch()->id, $this->date, 'day', 'all');

    expect($report['counts']['void'])->toBe(2)
        ->and($report['counts']['cancel'])->toBe(1)
        ->and($report['rows'])->toHaveCount(3)
        ->and($report['rows'][0]['approver'])->not->toBe('')
        ->and($report['rows'][0]['requester'])->not->toBe('');
});

it('filters to one approval kind', function (): void {
    $report = app(ReportService::class)->approvals(Hotel::branch()->id, $this->date, 'day', 'void');

    expect($report['rows'])->toHaveCount(2)
        ->and(collect($report['rows'])->pluck('group')->unique()->all())->toBe(['void']);
});

it('renders for admin and exports CSV, but not for front desk', function (): void {
    $this->actingAs(Hotel::user('ramil.ortega'))->get(route('reports.approvals', ['date' => $this->date]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Reports/Approvals')->where('report.counts.void', 2));

    $csv = $this->actingAs(Hotel::user('ramil.ortega'))->get(route('reports.approvals', ['date' => $this->date, 'export' => 'csv']));
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv');

    $this->actingAs(Hotel::frontdesk())->get(route('reports.approvals', ['date' => $this->date]))->assertForbidden();
});
