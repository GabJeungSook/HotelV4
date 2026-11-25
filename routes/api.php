<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HousekeepingController;
use App\Http\Controllers\Api\KioskController;
use Illuminate\Support\Facades\Route;

/*
| JSON API for the native mobile apps (kiosk tablet + roomboy phone). Token auth via Sanctum.
| Every endpoint wraps the same Actions, Queries, Requests and policies the web uses, so the
| rules (shift-binding, append-only money, module flags, branch scope) are shared, not rewritten.
*/

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('api.auth.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

    // Roomboy phone.
    Route::middleware(['role:housekeeper', 'module:housekeeping_enabled'])->prefix('housekeeping')->group(function (): void {
        Route::get('/queue', [HousekeepingController::class, 'queue'])->name('api.housekeeping.queue');
        Route::get('/history', [HousekeepingController::class, 'history'])->name('api.housekeeping.history');
        Route::post('/tasks/{task}/start', [HousekeepingController::class, 'start'])->whereNumber('task')->name('api.housekeeping.start');
        Route::get('/tasks/{task}', [HousekeepingController::class, 'show'])->whereNumber('task')->name('api.housekeeping.show');
        Route::post('/tasks/{task}/finish', [HousekeepingController::class, 'finish'])->whereNumber('task')->name('api.housekeeping.finish');
    });

    // Lobby kiosk tablet.
    Route::middleware(['role:kiosk', 'module:kiosk_enabled'])->prefix('kiosk')->group(function (): void {
        Route::get('/welcome', [KioskController::class, 'welcome'])->name('api.kiosk.welcome');
        Route::get('/rooms', [KioskController::class, 'rooms'])->name('api.kiosk.rooms');
        Route::get('/offer', [KioskController::class, 'offer'])->name('api.kiosk.offer');
        Route::post('/hold', [KioskController::class, 'hold'])->name('api.kiosk.hold');
        Route::get('/held/{code}', [KioskController::class, 'held'])->where('code', '[A-Z0-9-]+')->name('api.kiosk.held');
    });
});
