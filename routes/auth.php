<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PinController;
use Illuminate\Support\Facades\Route;

// Sign in (1), sign out, switch user by PIN (3), set your PIN, unlock PIN entry (SYSTEM_DESIGN §4.2).
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/password/change', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password/change', [PasswordController::class, 'update'])->name('password.update');
    Route::get('/pin/setup', [PinController::class, 'edit'])->name('pin.edit');
    Route::put('/pin/setup', [PinController::class, 'update'])->name('pin.update');
    Route::post('/pin/unlock', [PinController::class, 'unlock'])->name('pin.unlock');
    Route::post('/switch-user', [AuthController::class, 'switch'])->middleware(['pin.set', 'role:frontdesk,supervisor'])->name('switch');
});
