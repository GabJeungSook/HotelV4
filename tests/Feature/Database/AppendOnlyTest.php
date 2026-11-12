<?php

declare(strict_types=1);

use App\Exceptions\AppendOnlyViolation;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\CashCount;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PinAttempt;
use App\Models\PrintLog;
use App\Models\RoomStatusLog;
use App\Models\ShiftForwardedStay;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;

/** DATABASE_SCHEMA §1 append-only tables, plus pin_attempts and print_logs (ROADMAP Phase 2). */
dataset('append-only models', [
    FolioLine::class, Payment::class, PaymentAllocation::class, CashCount::class, StockMovement::class,
    ShiftForwardedStay::class, RoomStatusLog::class, Approval::class, AuditLog::class, PinAttempt::class, PrintLog::class,
]);

it('refuses to update an append-only row', function (string $class): void {
    /** @var Model $row */
    $row = $class::factory()->create();
    $before = $row->fresh()?->getAttributes();

    expect(fn () => $row->forceFill(['created_at' => '2030-01-01 00:00:00'])->save())->toThrow(AppendOnlyViolation::class);
    expect($row->fresh()?->getAttributes())->toBe($before);
})->with('append-only models');

it('refuses to delete an append-only row', function (string $class): void {
    /** @var Model $row */
    $row = $class::factory()->create();

    expect(fn () => $row->delete())->toThrow(AppendOnlyViolation::class);
    expect($class::query()->withoutGlobalScopes()->whereKey($row->getKey())->exists())->toBeTrue();
})->with('append-only models');
