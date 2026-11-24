<?php

declare(strict_types=1);

use App\Enums\ApprovalAction;
use App\Enums\BranchStatus;
use App\Enums\CashMovementKind;
use App\Enums\CleaningResult;
use App\Enums\CleaningStatus;
use App\Enums\CleaningTrigger;
use App\Enums\DepositType;
use App\Enums\DiscountKind;
use App\Enums\ExtensionRule;
use App\Enums\HallBookingStatus;
use App\Enums\HallPackageKind;
use App\Enums\HallPricing;
use App\Enums\LineKind;
use App\Enums\Outlet;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PinContext;
use App\Enums\PosMode;
use App\Enums\PrintDocument;
use App\Enums\PrintFormat;
use App\Enums\ProductKind;
use App\Enums\ReasonKind;
use App\Enums\ReportGroup;
use App\Enums\ReportSection;
use App\Enums\Role;
use App\Enums\RoomStatus;
use App\Enums\ShiftStatus;
use App\Enums\StayKind;
use App\Enums\StaySource;
use App\Enums\StayState;
use App\Enums\StockMovementKind;
use Illuminate\Support\Facades\DB;

/** Each PHP enum has exactly the values of its MySQL ENUM column (TRD §3.7). */
$enumColumns = [
    'branches.status' => BranchStatus::class,
    'branches.extension_rule' => ExtensionRule::class,
    'users.role' => Role::class,
    'rooms.status' => RoomStatus::class,
    'stays.stay_kind' => StayKind::class,
    'discounts.kind' => DiscountKind::class,
    'shift_sessions.status' => ShiftStatus::class,
    'cash_movements.kind' => CashMovementKind::class,
    'stays.state' => StayState::class,
    'stays.source' => StaySource::class,
    'folio_lines.kind' => LineKind::class,
    'payments.kind' => PaymentKind::class,
    'payments.method' => PaymentMethod::class,
    'payments.deposit_type' => DepositType::class,
    'products.kind' => ProductKind::class,
    'products.report_group' => ReportGroup::class,
    'products.outlet' => Outlet::class,
    'pos_orders.mode' => PosMode::class,
    'stock_movements.kind' => StockMovementKind::class,
    'cleaning_tasks.trigger_kind' => CleaningTrigger::class,
    'cleaning_tasks.status' => CleaningStatus::class,
    'cleaning_tasks.result' => CleaningResult::class,
    'approvals.action' => ApprovalAction::class,
    'reasons.kind' => ReasonKind::class,
    'pin_attempts.context' => PinContext::class,
    'print_logs.document' => PrintDocument::class,
    'print_logs.format' => PrintFormat::class,
    'daily_payment_summaries.method' => PaymentMethod::class,
    'daily_report_lines.section' => ReportSection::class,
    'hall_packages.kind' => HallPackageKind::class,
    'hall_packages.pricing' => HallPricing::class,
    'hall_bookings.status' => HallBookingStatus::class,
    'hall_booking_items.pricing' => HallPricing::class,
];

/** @return list<string> */
function enumValuesOf(string $table, string $column): array
{
    $type = DB::scalar(
        'SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
        [$table, $column],
    );
    preg_match_all("/'([^']*)'/", (string) $type, $m);

    return $m[1];
}

it('matches the MySQL ENUM values', function (string $column, string $enum): void {
    [$table, $name] = explode('.', $column);

    expect(array_column($enum::cases(), 'value'))->toBe(enumValuesOf($table, $name));
})->with(collect($enumColumns)->map(fn (string $enum, string $column): array => [$column, $enum])->all());

it('covers every ENUM column of the database', function () use ($enumColumns): void {
    $columns = collect(DB::select(
        "SELECT CONCAT(table_name, '.', column_name) AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND data_type = 'enum'"
    ))->pluck('name')->sort()->values()->all();

    // rates.stay_kind is the hourly/overnight subset of StayKind; it is checked by rates_hours_chk and the cast.
    expect($columns)->toBe(collect(array_keys($enumColumns))->push('rates.stay_kind')->sort()->values()->all());
});

it('gives every enum case a label', function (string $enum): void {
    foreach ($enum::cases() as $case) {
        expect($case->label())->toBeString()->not->toBe('');
    }
})->with(array_values(array_unique($enumColumns)));
