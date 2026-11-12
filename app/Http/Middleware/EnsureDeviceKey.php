<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives this computer a long-lived random key (SYSTEM_DESIGN §7, "Which computer this is").
 * It identifies the computer for the PIN lockout only: no login, no user data. The key is put
 * on the request at once, so the first request of a new computer already has one.
 */
final class EnsureDeviceKey
{
    public const COOKIE = 'device_key';

    /** Five years, in minutes. */
    private const LIFETIME = 60 * 24 * 365 * 5;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->cookie(self::COOKIE);
        $fresh = ! is_string($key) || ! Str::isUuid($key);

        if ($fresh) {
            $key = (string) Str::uuid();
            $request->cookies->set(self::COOKIE, $key);
        }

        $response = $next($request);

        if ($fresh) {
            $response->headers->setCookie(cookie(self::COOKIE, $key, self::LIFETIME, secure: (bool) config('session.secure'), httpOnly: true, sameSite: 'lax'));
        }

        return $response;
    }

    public static function of(Request $request): string
    {
        $key = $request->cookie(self::COOKIE);

        return is_string($key) && $key !== '' ? $key : 'unknown';
    }
}
