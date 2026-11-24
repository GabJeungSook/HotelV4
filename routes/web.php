<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\WelcomeController;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    // All notifications (the full list behind the top-bar bell). The desk, back office, admin and
    // owner have a feed; the kiosk and housekeeper phones do not.
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->middleware('role:frontdesk,supervisor,backoffice,admin,owner')
        ->name('notifications');
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

    // Dev quick sign-in: become a seeded user of any role, no password. Developer machine only;
    // the buttons for it show on the sign-in page only when the app runs in the local environment.
    Route::get('/dev/login/{role}', function (Request $request, string $role, Clock $clock) {
        $target = Role::tryFrom($role);
        abort_unless($target instanceof Role, 404);

        $user = User::query()->withoutGlobalScopes()
            ->where('role', $target)
            ->where('is_active', true)
            ->orderByRaw('pin_hash is null') // prefer a user who already has a PIN set
            ->orderBy('id')
            ->first();
        abort_unless($user instanceof User, 404, "No seeded {$role} user to sign in as.");

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('homi.signed_in_at', $clock->now()->toIso8601String());

        $response = redirect()->route('home');
        if ($user->branch_id !== null && $target !== Role::Owner && $target !== Role::Kiosk) {
            $response->withCookie(cookie(AuthController::BRANCH_COOKIE, (string) $user->branch_id, 60 * 24 * 365, secure: (bool) config('session.secure'), httpOnly: true, sameSite: 'lax'));
        }

        return $response;
    })->name('dev.login');
}
