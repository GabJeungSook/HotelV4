<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\PlaceholderController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Phase 0 placeholder. The welcome page (mockup 59) is built in Phase 10.
Route::get('/', fn () => Inertia::render('Welcome'))->name('welcome');

require __DIR__.'/auth.php';
require __DIR__.'/frontdesk.php';

Route::middleware(['auth', 'pin.set'])->group(function (): void {
    // The role home (APP_FLOW §1.1): the redirect after sign-in and the button on screen 51.
    Route::get('/home', HomeController::class)->name('home');

    // Connection check for the bar (50), every 5 s while failing (APP_FLOW §8.1).
    Route::get('/heartbeat', fn () => response()->noContent())->name('heartbeat');

    // Homes built in later phases get a plain placeholder until then (ROADMAP Phase 4).
    Route::get('/dashboard', [PlaceholderController::class, 'dashboard'])->middleware('role:admin,backoffice,supervisor')->name('dashboard');
    Route::get('/owner', [PlaceholderController::class, 'owner'])->middleware('role:owner')->name('owner.dashboard');
    Route::get('/housekeeping', [PlaceholderController::class, 'housekeeping'])->middleware(['role:housekeeper', 'module:housekeeping_enabled'])->name('housekeeping.index');
    Route::get('/kiosk', [PlaceholderController::class, 'kiosk'])->middleware(['role:kiosk', 'module:kiosk_enabled'])->name('kiosk.index');
});

// Component gallery (ROADMAP phase 1), local only: every Nuxt UI component we use, every
// component of our own and every chart, in every state, inside any of the six layouts.
if (app()->environment('local')) {
    Route::get('/dev/components', function (Request $request) {
        $layouts = ['backoffice', 'frontdesk', 'admin', 'owner', 'kiosk', 'phone'];
        $layout = $request->query('layout');

        return Inertia::render('Dev/Components', [
            'layout' => is_string($layout) && in_array($layout, $layouts, true) ? $layout : 'backoffice',
            'layouts' => $layouts,
        ]);
    })->name('dev.components');
}
