<?php

declare(strict_types=1);

use App\Http\Controllers\HousekeepingController;
use Illuminate\Support\Facades\Route;

// Housekeeping on the phone (TRD §4.1, SYSTEM_DESIGN §4.2): housekeepers of a branch with the
// housekeeping module on. Screens 32–35, APP_FLOW §4.17.
Route::middleware(['auth', 'role:housekeeper', 'module:housekeeping_enabled'])->group(function (): void {
    Route::get('/housekeeping', [HousekeepingController::class, 'index'])->name('housekeeping.index');
    Route::get('/housekeeping/history', [HousekeepingController::class, 'history'])->name('housekeeping.history');
    Route::post('/housekeeping/{task}/start', [HousekeepingController::class, 'start'])->whereNumber('task')->name('housekeeping.start');
    Route::get('/housekeeping/{task}', [HousekeepingController::class, 'show'])->whereNumber('task')->name('housekeeping.show');
    Route::post('/housekeeping/{task}/finish', [HousekeepingController::class, 'finish'])->whereNumber('task')->name('housekeeping.finish');
});
