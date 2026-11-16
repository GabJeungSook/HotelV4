<?php

declare(strict_types=1);

use App\Actions\Admin\SaveRate;
use App\Enums\StayKind;
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

    app(SaveRate::class)->handle(Hotel::user('ramil.ortega'), $branch, $type->id, StayKind::Hourly, 6, Money::ofPesos(380));

    $rows = collect(app(ReportService::class)->audit($branch, '2026-11-10')['rows'])->filter(fn (array $r): bool => str_starts_with($r['action'], 'Setup'));

    expect($rows)->not->toBeEmpty()
        ->and($rows->first()['action'])->toContain('rate')
        ->and($rows->first()['by'])->toBe('Ramil Ortega');
});
