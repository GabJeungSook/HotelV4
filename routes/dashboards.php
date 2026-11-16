<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ExceptionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Owner\DashboardController as OwnerDashboardController;
use App\Http\Controllers\Owner\SummaryController;
use App\Http\Controllers\StatsController;
use App\Http\Middleware\EnsureBranchOpened;
use Illuminate\Support\Facades\Route;

// Dashboards (60, 61), Stats (63), Needs attention (49) and the owner summary (39)
// (TRD §4.1, §4.11; APP_FLOW §2 and §8.1). Front desk and housekeepers never reach them.
Route::middleware(['auth', 'pin.set'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->middleware(['role:admin,backoffice,supervisor,owner', EnsureBranchOpened::class])->name('dashboard');

    Route::get('/stats', [StatsController::class, 'index'])->middleware('role:admin,backoffice,supervisor,owner')->name('stats');

    Route::middleware(['role:admin,supervisor,owner', EnsureBranchOpened::class])->group(function (): void {
        Route::get('/admin/exceptions', [ExceptionController::class, 'index'])->name('admin.exceptions');
        Route::post('/shift/{shift}/force-close', [ExceptionController::class, 'forceClose'])->whereNumber('shift')->name('shift.force-close');
        Route::post('/housekeeping/{task}/reset', [ExceptionController::class, 'resetTask'])->whereNumber('task')->name('housekeeping.reset');
    });

    Route::middleware('role:owner')->group(function (): void {
        Route::get('/owner', [OwnerDashboardController::class, 'index'])->name('owner.dashboard');
        Route::get('/owner/summary', [SummaryController::class, 'index'])->name('owner.summary');
    });
});
