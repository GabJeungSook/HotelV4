<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// The public welcome page (59).
Route::get('/', [WelcomeController::class, 'index'])->name('welcome');

require __DIR__.'/auth.php';
require __DIR__.'/frontdesk.php';
require __DIR__.'/housekeeping.php';
require __DIR__.'/kiosk.php';
require __DIR__.'/reports.php';
require __DIR__.'/admin.php';
require __DIR__.'/owner.php';
require __DIR__.'/dashboards.php';

Route::middleware(['auth', 'pin.set'])->group(function (): void {
    // The role home (APP_FLOW §1.1): the redirect after sign-in and the button on screen 51.
    Route::get('/home', HomeController::class)->name('home');

    // Connection check for the bar (50), every 5 s while failing (APP_FLOW §8.1).
    Route::get('/heartbeat', fn () => response()->noContent())->name('heartbeat');
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
