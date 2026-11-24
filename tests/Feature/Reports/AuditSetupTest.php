<?php

declare(strict_types=1);

use App\Actions\Admin\SaveRate;
use App\Enums\StayKind;
use App\Models\AuditLog;
use App\Models\RoomType;
use App\Services\ReportService;
use App\Support\Money;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\Hotel;

/*
| Mockup 45: "Price changes are logged in the audit trail (43)". Setup changes written by the
| admin screens (44–48) appear on the audit trail with who made them.
*/

it('shows a rate change on the audit trail', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-10 02:00:00');
    $branch = Hotel::branch()->id;
    $type = RoomType::query()->where('name', 'Standard')->firstOrFail();

    app(SaveRate::class)->handle(Hotel::user('ramil.ortega'), $branch, $type->id, null, null, StayKind::Hourly, 6, Money::ofPesos(380));

    $rows = collect(app(ReportService::class)->audit($branch, '2026-11-10')['rows'])->filter(fn (array $r): bool => str_starts_with($r['action'], 'Setup'));

    expect($rows)->not->toBeEmpty()
        ->and($rows->first()['action'])->toContain('rate')
        ->and($rows->first()['by'])->toBe('Ramil Ortega');
});

it('formats a day close on the audit trail in words, not raw JSON', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-10 02:00:00');
    $branch = Hotel::branch()->id;

    AuditLog::query()->create([
        'branch_id' => $branch,
        'actor_id' => Hotel::user('ramil.ortega')->id,
        'action' => 'day.closed',
        'subject_type' => 'day_closing',
        'subject_id' => 1,
        'before_json' => null,
        'after_json' => ['date' => '2026-11-10', 'day_z_no' => 7, 'net_sales' => 429000],
        'created_at' => '2026-11-10 02:00:00',
    ]);

    $row = collect(app(ReportService::class)->audit($branch, '2026-11-10')['rows'])->firstWhere('action', 'Day closed');

    expect($row)->not->toBeNull()
        ->and($row['details'])->toBe('D-0007 · net ₱4,290.00')
        ->and($row['details'])->not->toContain('{');
});
