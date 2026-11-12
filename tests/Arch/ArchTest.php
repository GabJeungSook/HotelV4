<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Architecture rules (TRD §3, §12; ROADMAP Phase 0)
|--------------------------------------------------------------------------
*/

arch('app/ never calls now() or today(); time comes from App\Support\Clock')
    ->expect('App')
    ->not->toUse(['now', 'today']);

arch('controllers do not use the DB facade')
    ->expect('App\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'DB', 'Illuminate\Database\DatabaseManager']);

arch('no debugging helpers are left in app/')
    ->expect(['dd', 'dump', 'ddd', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('every PHP file in app/ declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('support value objects are final')
    ->expect('App\Support')
    ->toBeFinal();

it('has no Carbon::now(), Date::now() or similar static clock calls in app/ outside Clock', function (): void {
    $app = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app';
    $offenders = [];

    $files = Finder::create()->files()->in($app)->name('*.php');

    foreach ($files as $file) {
        if ($file->getRelativePathname() === 'Support'.DIRECTORY_SEPARATOR.'Clock.php') {
            continue;
        }

        $code = $file->getContents();

        if (preg_match('/\b(Carbon|CarbonImmutable|Date|DateTime|DateTimeImmutable)::(now|today|yesterday|tomorrow)\s*\(|new\s+\\\\?(Carbon|CarbonImmutable|DateTime|DateTimeImmutable)\s*\(\s*\)|\btime\s*\(\s*\)|\bdate\s*\(/', $code) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

/*
| Branch scope and append-only (TRD §3.6, §3.10). The Feature test
| ModelsTest checks this list against the tables that have branch_id NOT NULL.
*/

arch('operational models use BelongsToBranch')
    ->expect([
        'App\Models\ShiftDefinition', 'App\Models\Floor', 'App\Models\RoomType', 'App\Models\Room',
        'App\Models\Rate', 'App\Models\ExtensionRate', 'App\Models\Discount', 'App\Models\TransferReason',
        'App\Models\ExpenseCategory', 'App\Models\ShiftSession', 'App\Models\CashMovement', 'App\Models\Stay',
        'App\Models\FolioLine', 'App\Models\Payment', 'App\Models\Product', 'App\Models\PosOrder',
        'App\Models\StockMovement', 'App\Models\CleaningTask', 'App\Models\Approval', 'App\Models\DayClosing',
        'App\Models\Reason', 'App\Models\PrintLog', 'App\Models\SummaryDay', 'App\Models\DailySummary',
        'App\Models\HourlySalesSummary', 'App\Models\DailyPaymentSummary', 'App\Models\DailyReportLine',
    ])
    ->toUseTrait('App\Models\Concerns\BelongsToBranch');

arch('ledger and log models are append-only')
    ->expect([
        'App\Models\FolioLine', 'App\Models\Payment', 'App\Models\PaymentAllocation', 'App\Models\CashCount',
        'App\Models\StockMovement', 'App\Models\ShiftForwardedStay', 'App\Models\RoomStatusLog',
        'App\Models\Approval', 'App\Models\AuditLog', 'App\Models\PinAttempt', 'App\Models\PrintLog',
    ])
    ->toUseTrait('App\Models\Concerns\AppendOnly');

arch('Today (62) carries no money: its query never touches Money or the cash ledger model')
    ->expect('App\Queries\TodayQuery')
    ->not->toUse(['App\Support\Money', 'App\Models\Payment', 'App\Models\CashMovement', 'App\Services\CashMath']);

arch('front desk controllers never read the open shift totals or expected cash')
    ->expect(['App\Http\Controllers\TodayController', 'App\Http\Controllers\BoardController', 'App\Http\Controllers\StayController', 'App\Http\Controllers\ShiftController'])
    ->not->toUse(['App\Services\CashMath', 'App\Models\CashCount', 'App\Models\CashMovement']);
