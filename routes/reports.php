<?php

declare(strict_types=1);

use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// Reports (TRD §4.1, APP_FLOW §2 and §8.1, SYSTEM_DESIGN §4.2): back office, supervisor, admin
// and owner; never front desk. Screens 36–43, 37b, 37c, 38b and the shifts list. Exports
// (TRD §6): the Z-report and the day Z as PDF and CSV, the other reports with ?export=csv.
Route::middleware(['auth', 'pin.set', 'role:backoffice,supervisor,admin,owner'])->prefix('reports')->name('reports.')->group(function (): void {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::get('/shifts', [ReportController::class, 'shifts'])->name('shifts');
    Route::get('/z', [ReportController::class, 'zLatest'])->name('z.latest');

    Route::get('/shifts/{shift}', [ReportController::class, 'shift'])->whereNumber('shift')->name('shift');
    Route::post('/shifts/{shift}/print', [ReportController::class, 'shiftPrint'])->whereNumber('shift')->name('shift.print');
    Route::get('/shifts/{shift}/z', [ReportController::class, 'zReport'])->whereNumber('shift')->name('z');
    Route::get('/shifts/{shift}/z.pdf', [ReportController::class, 'zPdf'])->whereNumber('shift')->name('z.pdf');
    Route::get('/shifts/{shift}/z.csv', [ReportController::class, 'zCsv'])->whereNumber('shift')->name('z.csv');
    Route::get('/shifts/{shift}/z.html', [ReportController::class, 'zHtml'])->whereNumber('shift')->name('z.html');

    Route::get('/daily', [ReportController::class, 'daily'])->name('daily');
    Route::get('/daily/close', [ReportController::class, 'closeDay'])->name('daily.close');
    Route::post('/daily/close', [ReportController::class, 'closeDayStore'])->name('daily.close.store');
    Route::get('/daily/{date}/z.pdf', [ReportController::class, 'dayPdf'])->name('daily.z.pdf');
    Route::get('/daily/{date}/z.csv', [ReportController::class, 'dayCsv'])->name('daily.z.csv');
    Route::post('/daily/{date}/print', [ReportController::class, 'dayPrint'])->name('daily.print');

    Route::get('/guests', [ReportController::class, 'guests'])->name('guests');
    Route::get('/occupancy', [ReportController::class, 'occupancy'])->name('occupancy');
    Route::get('/housekeeping', [ReportController::class, 'housekeeping'])->name('housekeeping');
    Route::get('/expenses', [ReportController::class, 'expenses'])->name('expenses');
    Route::get('/inventory', [ReportController::class, 'inventory'])->name('inventory');
    Route::get('/audit', [ReportController::class, 'audit'])->name('audit');
    Route::get('/approvals', [ReportController::class, 'approvals'])->name('approvals');
});
