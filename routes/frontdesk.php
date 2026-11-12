<?php

declare(strict_types=1);

use App\Http\Controllers\BoardController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\StayController;
use App\Http\Controllers\TodayController;
use Illuminate\Support\Facades\Route;

// Front desk (TRD §4.1, SYSTEM_DESIGN §4.2): frontdesk and supervisor; shift membership on
// everything except /shift/open. Phase 4 screens: 2, 2b, 4, 6, 8–18, 62.
Route::middleware(['auth', 'pin.set', 'role:frontdesk,supervisor'])->group(function (): void {
    Route::get('/shift/open', [ShiftController::class, 'create'])->name('shift.open');
    Route::post('/shift/open', [ShiftController::class, 'store'])->name('shift.store');
    Route::post('/shift/join', [ShiftController::class, 'join'])->name('shift.join');

    Route::middleware('shift.member')->group(function (): void {
        Route::get('/today', [TodayController::class, 'index'])->name('today');
        Route::get('/board', [BoardController::class, 'index'])->name('board');

        Route::get('/stays/create', [StayController::class, 'create'])->name('stays.create');
        Route::post('/stays', [StayController::class, 'store'])->name('stays.store');
        Route::get('/stays/{stay}', [StayController::class, 'show'])->name('stays.show');
        Route::post('/stays/{stay}/charges', [StayController::class, 'charge'])->name('stays.charge');
        Route::post('/stays/{stay}/payments', [StayController::class, 'pay'])->name('stays.pay');
        Route::post('/stays/{stay}/lines/{line}/void', [StayController::class, 'void'])->name('stays.void');
        Route::post('/stays/{stay}/cancel', [StayController::class, 'cancel'])->name('stays.cancel');
        Route::get('/stays/{stay}/extend', [StayController::class, 'extend'])->name('stays.extend');
        Route::post('/stays/{stay}/extend', [StayController::class, 'extendStore'])->name('stays.extend.store');
        Route::get('/stays/{stay}/transfer', [StayController::class, 'transfer'])->name('stays.transfer');
        Route::post('/stays/{stay}/transfer', [StayController::class, 'transferStore'])->name('stays.transfer.store');
        Route::get('/stays/{stay}/checkout', [StayController::class, 'checkout'])->name('stays.checkout');
        Route::post('/stays/{stay}/checkout', [StayController::class, 'checkoutStore'])->name('stays.checkout.store');
    });
});
