<?php

declare(strict_types=1);

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Owner\AccountController;
use App\Http\Controllers\Owner\BranchController;
use Illuminate\Support\Facades\Route;

// Owner screens (TRD §4.1, APP_FLOW §2 and §8.1): branches (55), add branch (56), owner accounts.
Route::middleware(['auth', 'pin.set', 'role:owner'])->prefix('owner')->name('owner.')->group(function (): void {
    Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::get('/branches/create', [BranchController::class, 'create'])->name('branches.create');
    Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
    Route::post('/branches/close', [BranchController::class, 'close'])->name('branches.close');
    Route::post('/branches/{branch}/open', [BranchController::class, 'open'])->whereNumber('branch')->name('branches.open');
    Route::patch('/branches/{branch}', [BranchController::class, 'update'])->whereNumber('branch')->name('branches.update');

    // Activity log across every active branch.
    Route::get('/activity', [ActivityController::class, 'all'])->name('activity');

    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::patch('/accounts/{user}', [AccountController::class, 'update'])->whereNumber('user')->name('accounts.update');
});
