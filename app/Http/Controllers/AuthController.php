<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\SignIn;
use App\Actions\Auth\VerifyPin;
use App\Enums\BranchStatus;
use App\Enums\PinContext;
use App\Enums\Role;
use App\Exceptions\ActionRefused;
use App\Http\Middleware\EnsureDeviceKey;
use App\Http\Requests\SignInRequest;
use App\Http\Requests\SwitchUserRequest;
use App\Models\Branch;
use App\Models\User;
use App\Queries\ShiftQuery;
use App\Queries\TodayQuery;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/** Sign in (1), sign out and switch user by PIN (3): APP_FLOW §4.1 and §4.4. */
final class AuthController extends Controller
{
    public const BRANCH_COOKIE = 'homi_branch';

    /** One year, in minutes (TRD §4.11). */
    private const BRANCH_COOKIE_MINUTES = 60 * 24 * 365;

    public function create(Request $request, Clock $clock, TodayQuery $today): Response
    {
        $hour = (int) $clock->local()->format('G');
        $part = match (true) {
            $hour >= 5 && $hour < 12 => 'morning',
            $hour >= 12 && $hour < 18 => 'afternoon',
            default => 'evening',
        };

        $remembered = $request->cookie(self::BRANCH_COOKIE);
        $branch = is_string($remembered) && ctype_digit($remembered)
            ? Branch::query()->withoutGlobalScopes()->where('status', BranchStatus::Active)->find((int) $remembered)
            : null;

        return Inertia::render('Auth/Login', [
            'part' => $part,
            'now' => $clock->now()->toIso8601String(),
            'photo' => [
                'src' => "/images/photos/signin-{$part}.jpg",
                'srcset' => "/images/photos/signin-{$part}-1200.jpg 1200w, /images/photos/signin-{$part}.jpg 2000w",
            ],
            // The framed photo panel cross-fades three photos, the part of the day first (mockup v6).
            'photos' => [
                ['src' => "/images/photos/signin-{$part}.jpg", 'srcset' => "/images/photos/signin-{$part}-1200.jpg 1200w, /images/photos/signin-{$part}.jpg 2000w"],
                ['src' => '/images/photos/room-deluxe.jpg', 'srcset' => ''],
                ['src' => '/images/photos/lobby.jpg', 'srcset' => '/images/photos/lobby-1200.jpg 1200w, /images/photos/lobby.jpg 2000w'],
            ],
            'facts' => $branch === null ? null : $today->signInFacts($branch->id),
            // Dev quick sign-in buttons, developer machine only (routes/web.php `dev.login`).
            'dev' => app()->environment('local'),
        ]);
    }

    public function store(SignInRequest $request, SignIn $signIn, Clock $clock): RedirectResponse
    {
        $user = $signIn->handle($request->string('username')->toString(), $request->string('password')->toString());

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('homi.signed_in_at', $clock->now()->toIso8601String());

        $response = redirect()->route('home');

        // Remember the branch on this computer for the sign-in counts. An owner has no branch.
        if ($user->branch_id !== null && $user->role !== Role::Owner && $user->role !== Role::Kiosk) {
            $response->withCookie(cookie(self::BRANCH_COOKIE, (string) $user->branch_id, self::BRANCH_COOKIE_MINUTES, secure: (bool) config('session.secure'), httpOnly: true, sameSite: 'lax'));
        }

        return $response;
    }

    public function destroy(Request $request): RedirectResponse
    {
        // Signing out never touches the shift, the drawer or the members (APP_FLOW §6 case 12).
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Switch user (3): the session becomes another member of the open shift once their PIN is
     * right. The shift, the drawer and the page stay as they were. Three wrong PINs end the
     * session (DomainErrorResponse, PinInvalid::$mustSignIn).
     */
    public function switch(SwitchUserRequest $request, VerifyPin $verify, ShiftQuery $shifts, Clock $clock): RedirectResponse
    {
        /** @var User $current */
        $current = $request->user();
        $target = User::query()->findOrFail($request->integer('user_id'));
        $shift = $current->branch_id === null ? null : $shifts->open($current->branch_id);

        if ($shift === null || $target->id === $current->id || $target->branch_id !== $current->branch_id
            || ! $target->is_active || ! $shifts->isMember($shift->id, $target->id)) {
            throw ActionRefused::because('Only another member of the open shift can be switched to.');
        }

        $since = $request->session()->get('homi.signed_in_at');
        $verify->handle($target, $request->string('pin')->toString(), EnsureDeviceKey::of($request), PinContext::SwitchUser, is_string($since) ? CarbonImmutable::parse($since) : null);

        Auth::login($target);
        $request->session()->regenerate();
        $request->session()->put('homi.signed_in_at', $clock->now()->toIso8601String());
        Inertia::flash('toast', ['type' => 'success', 'message' => "Signed on as {$target->name}."]);

        return redirect()->back(fallback: route('home'));
    }
}
