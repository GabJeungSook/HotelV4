<?php

declare(strict_types=1);

use App\Http\Controllers\BoardController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\StayController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\TodayController;
use Illuminate\Support\Facades\Route;

// Front desk (TRD §4.1, SYSTEM_DESIGN §4.2): frontdesk and supervisor; shift membership on
// everything except /shift/open and the report of a closed shift. Screens 2, 2b, 4, 6, 8–26, 62, 19, 20.
Route::middleware(['auth', 'pin.set', 'role:frontdesk,supervisor'])->group(function (): void {
    Route::get('/shift/open', [ShiftController::class, 'create'])->name('shift.open');
    Route::post('/shift/open', [ShiftController::class, 'store'])->name('shift.store');
    Route::post('/shift/join', [ShiftController::class, 'join'])->name('shift.join');

    // The slip (26) of a closed shift: its members and supervisors, after the shift is gone.
    Route::get('/shift/{shift}/report', [ShiftController::class, 'report'])->whereNumber('shift')->name('shift.report');
    Route::post('/shift/{shift}/report/print', [ShiftController::class, 'reportPrint'])->whereNumber('shift')->name('shift.report.print');

    Route::middleware('shift.member')->group(function (): void {
        Route::get('/today', [TodayController::class, 'index'])->name('today');
        Route::get('/board', [BoardController::class, 'index'])->name('board');

        // Shift cash (21) and the close guide (22–25), APP_FLOW §4.15–4.16.
        Route::get('/shift', [ShiftController::class, 'show'])->name('shift.show');
        Route::post('/shift/movements', [ShiftController::class, 'movement'])->name('shift.movement');
        Route::post('/shift/movements/{movement}/void', [ShiftController::class, 'voidMovement'])->whereNumber('movement')->name('shift.movement.void');
        Route::get('/shift/close', [ShiftController::class, 'close'])->name('shift.close');
        Route::post('/shift/close', [ShiftController::class, 'closeStore'])->name('shift.close.store');
        Route::put('/shift/close/draft', [ShiftController::class, 'closeDraft'])->name('shift.close.draft');

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

        // POS (19) and stock (20), APP_FLOW §4.13–4.14: only where the branch sells products.
        Route::middleware('module:pos_enabled')->group(function (): void {
            Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
            Route::post('/pos', [PosController::class, 'store'])->name('pos.store');
            Route::post('/pos/{order}/void', [PosController::class, 'void'])->whereNumber('order')->name('pos.void');
            Route::get('/stock', [StockController::class, 'index'])->name('stock.index');
            Route::post('/stock', [StockController::class, 'store'])->name('stock.store');
        });
    });
});
