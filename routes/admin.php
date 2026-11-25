<?php

declare(strict_types=1);

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\Admin\HallController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RateController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Middleware\EnsureBranchOpened;
use Illuminate\Support\Facades\Route;

// Branch setup (TRD §4.1, APP_FLOW §2): screens 44–48. Admin of the branch; owner who opened it.
Route::middleware(['auth', 'pin.set', 'role:admin,owner', EnsureBranchOpened::class])->prefix('admin')->group(function (): void {
    // Activity log: every action of a business day for this branch.
    Route::get('/activity', [ActivityController::class, 'index'])->name('admin.activity');

    // Rooms (44), with the floors and room types tabs.
    Route::get('/rooms', [RoomController::class, 'index'])->name('admin.rooms');
    Route::post('/rooms', [RoomController::class, 'store'])->name('admin.rooms.store');
    Route::put('/rooms/{room}', [RoomController::class, 'update'])->whereNumber('room')->name('admin.rooms.update');
    Route::post('/floors', [RoomController::class, 'storeFloor'])->name('admin.floors.store');
    Route::put('/floors/{floor}', [RoomController::class, 'updateFloor'])->whereNumber('floor')->name('admin.floors.update');
    Route::post('/room-types', [RoomController::class, 'storeType'])->name('admin.room-types.store');
    Route::put('/room-types/{type}', [RoomController::class, 'updateType'])->whereNumber('type')->name('admin.room-types.update');

    // Rates (45): rate cells, extension blocks, discounts.
    Route::get('/rates', [RateController::class, 'index'])->name('admin.rates');
    Route::put('/rates', [RateController::class, 'update'])->name('admin.rates.update');
    Route::put('/extension-rates', [RateController::class, 'extension'])->name('admin.extension-rates.update');
    Route::post('/discounts', [RateController::class, 'storeDiscount'])->name('admin.discounts.store');
    Route::put('/discounts/{discount}', [RateController::class, 'updateDiscount'])->whereNumber('discount')->name('admin.discounts.update');

    // Branch settings (46): setting groups, shifts, reason lists.
    Route::get('/settings', [SettingController::class, 'index'])->name('admin.settings');
    Route::put('/settings', [SettingController::class, 'update'])->name('admin.settings.update');
    Route::post('/shift-definitions', [SettingController::class, 'storeShift'])->name('admin.shift-definitions.store');
    Route::put('/shift-definitions/{shift}', [SettingController::class, 'updateShift'])->whereNumber('shift')->name('admin.shift-definitions.update');
    Route::post('/lists/{list}', [SettingController::class, 'storeReason'])->where('list', '[a-z_]+')->name('admin.lists.store');
    Route::put('/lists/{list}/{reason}', [SettingController::class, 'updateReason'])->where('list', '[a-z_]+')->whereNumber('reason')->name('admin.lists.update');

    // Users (47).
    Route::get('/users', [UserController::class, 'index'])->name('admin.users');
    Route::post('/users', [UserController::class, 'store'])->name('admin.users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])->whereNumber('user')->name('admin.users.update');
    Route::post('/users/{user}/pin-reset', [UserController::class, 'resetPin'])->whereNumber('user')->name('admin.users.pin-reset');
    Route::post('/users/{user}/password-reset', [UserController::class, 'resetPassword'])->whereNumber('user')->name('admin.users.password-reset');
    Route::put('/users/{user}/active', [UserController::class, 'active'])->whereNumber('user')->name('admin.users.active');

    // Products (48).
    Route::get('/products', [ProductController::class, 'index'])->name('admin.products');
    Route::post('/products', [ProductController::class, 'store'])->name('admin.products.store');
    Route::put('/products/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('admin.products.update');

    // Event halls and packages (DATABASE_SCHEMA §4.14), when the branch has the module on.
    Route::middleware('module:event_hall_enabled')->group(function (): void {
        Route::get('/halls', [HallController::class, 'index'])->name('admin.halls');
        Route::post('/halls', [HallController::class, 'store'])->name('admin.halls.store');
        Route::put('/halls/{hall}', [HallController::class, 'update'])->whereNumber('hall')->name('admin.halls.update');
        Route::post('/hall-packages', [HallController::class, 'storePackage'])->name('admin.hall-packages.store');
        Route::put('/hall-packages/{package}', [HallController::class, 'updatePackage'])->whereNumber('package')->name('admin.hall-packages.update');
    });
});
