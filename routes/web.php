<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Phase 0 placeholder. The welcome page (mockup 59) is built in Phase 10.
Route::get('/', fn () => Inertia::render('Welcome'))->name('welcome');

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
