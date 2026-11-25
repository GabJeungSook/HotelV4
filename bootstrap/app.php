<?php

declare(strict_types=1);

use App\Exceptions\DomainException;
use App\Http\DomainErrorResponse;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureDeviceKey;
use App\Http\Middleware\EnsureModule;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePinSet;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureShiftMember;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\CurrentBranch;
use App\Support\Navigation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);

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
        $exceptions->render(function (DomainException $e, Request $request) {
            $handler = app(DomainErrorResponse::class);

            // The mobile API gets the refusal as JSON; the web keeps its dialog/redirect.
            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? $handler->json($e)
                : $handler->toResponse($e, $request);
        });

        // Every logged error carries who, where and the request id (TRD §8).
        $exceptions->context(fn (): array => [
            'request_id' => AssignRequestId::current(),
            'user_id' => request()->user()?->getAuthIdentifier(),
            'branch_id' => app(CurrentBranch::class)->id(),
            'route' => request()->route()?->getName() ?? request()->path(),
        ]);

        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : $response->getStatusCode();
            $user = $request->user();
            $json = $request->expectsJson() && ! $request->header('X-Inertia');

            // A form sent after the session ended (419): back to the sign-in; the shift stays open.
            if ($status === 419 && ! $json) {
                Inertia::flash('toast', ['type' => 'info', 'message' => 'Your session ended, so nothing was saved. Sign in again.']);

                return redirect()->guest(route('login'));
            }

            // Wrong role or switched-off module: the no-permission page (51), naming the account
            // and offering the way back to the role's home.
            if ($status === 403 && $user instanceof User && ! $json) {
                return Inertia::render('Errors/Forbidden', [
                    'account' => ['name' => $user->name, 'role' => $user->role->label()],
                    'home' => route('home', absolute: false),
                    'homeLabel' => Navigation::homeLabel($user),
                ])->toResponse($request)->setStatusCode(403);
            }

            // Unexpected errors: the transaction has rolled back. In development the debug page
            // stays; elsewhere the user reads "Something went wrong. Nothing was saved." with the
            // request id (TRD §8, APP_FLOW §6 case 55).
            $unexpected = $status >= 500 && $status !== 503 && ! config('app.debug');
            $requestId = AssignRequestId::current($request);

            if ($unexpected && $json) {
                return response()->json(['message' => 'Something went wrong. Nothing was saved.', 'request_id' => $requestId], $status);
            }

            // A form keeps its page and what was typed; the layout shows the red dialog.
            if ($unexpected && $request->header('X-Inertia') && ! $request->isMethod('GET')) {
                Inertia::flash('error', ['code' => 'server_error', 'message' => 'Something went wrong. Nothing was saved.', 'request_id' => $requestId]);

                return back(303);
            }

            if (($unexpected || in_array($status, [404, 503], true)) && ! $json) {
                return Inertia::render('Errors/Error', [
                    'status' => $status,
                    'requestId' => $unexpected ? $requestId : null,
                    'home' => $user instanceof User ? route('home', absolute: false) : route('welcome', absolute: false),
                    'homeLabel' => $user instanceof User ? Navigation::homeLabel($user) : 'Go to the front page',
                ])->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });
    })->create();
