<?php

declare(strict_types=1);

use App\Exceptions\DomainException;
use App\Http\DomainErrorResponse;
use App\Http\Middleware\EnsureDeviceKey;
use App\Http\Middleware\EnsureModule;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePinSet;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureShiftMember;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            EnsureDeviceKey::class,
            HandleInertiaRequests::class,
            EnsurePasswordChanged::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'module' => EnsureModule::class,
            'shift.member' => EnsureShiftMember::class,
            'pin.set' => EnsurePinSet::class,
        ]);

        // The device key only identifies the computer for the PIN lockout; the remembered
        // branch is a branch id for the sign-in counts. Neither needs encrypting.
        $middleware->encryptCookies(except: [EnsureDeviceKey::COOKIE, 'homi_branch']);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Domain errors become the screen's dialog or inline alert (SYSTEM_DESIGN §8). They are
        // expected refusals, not faults, so they are not written to the error log.
        $exceptions->dontReport(DomainException::class);
        $exceptions->render(fn (DomainException $e, Request $request) => app(DomainErrorResponse::class)->toResponse($e, $request));

        // Wrong role or switched-off module: the no-permission page (51), naming the account
        // and offering the way back to the role's home.
        $exceptions->respond(function ($response, Throwable $e, Request $request) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : $response->getStatusCode();
            $user = $request->user();

            if ($status !== 403 || ! $user instanceof User || $request->expectsJson() && ! $request->header('X-Inertia')) {
                return $response;
            }

            return Inertia::render('Errors/Forbidden', [
                'account' => ['name' => $user->name, 'role' => $user->role->label()],
                'home' => route('home', absolute: false),
                'homeLabel' => Navigation::homeLabel($user),
            ])->toResponse($request)->setStatusCode(403);
        });
    })->create();
