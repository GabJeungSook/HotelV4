<?php

declare(strict_types=1);

use App\Http\Controllers\KioskController;
use Illuminate\Support\Facades\Route;

// The lobby kiosk (TRD §4.1, SYSTEM_DESIGN §4.2): the kiosk device of a branch with the kiosk
// module on. Screens 27–31b, APP_FLOW §4.18. A kiosk account reaches nothing outside /kiosk
// (every other group requires another role), apart from /home and /heartbeat.
Route::middleware(['auth', 'role:kiosk', 'module:kiosk_enabled'])->prefix('kiosk')->group(function (): void {
    Route::get('/', [KioskController::class, 'index'])->name('kiosk.index');
    Route::get('/start', [KioskController::class, 'start'])->name('kiosk.start');
    Route::get('/details', [KioskController::class, 'details'])->name('kiosk.details');
    Route::post('/hold', [KioskController::class, 'hold'])->name('kiosk.hold');
    Route::get('/held/{code}', [KioskController::class, 'held'])->where('code', '[A-Z0-9-]+')->name('kiosk.held');

    // Kiosk checkout (reference: HotelV2): only when the branch turns it on.
    Route::middleware('module:kiosk_checkout_enabled')->group(function (): void {
        Route::get('/checkout', [KioskController::class, 'checkout'])->name('kiosk.checkout');
        Route::post('/checkout', [KioskController::class, 'checkoutLookup'])->name('kiosk.checkout.lookup');
        Route::post('/checkout/confirm', [KioskController::class, 'checkoutConfirm'])->name('kiosk.checkout.confirm');
        Route::get('/checkout/done', [KioskController::class, 'checkoutDone'])->name('kiosk.checkout.done');
    });
});
