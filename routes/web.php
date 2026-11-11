<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Phase 0 placeholder. The welcome page (mockup 59) is built in Phase 10.
Route::get('/', fn () => Inertia::render('Welcome'))->name('welcome');
