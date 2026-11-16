<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user with a temporary password replaces it before anything else, even before setting a PIN
 * (APP_FLOW §8.1 /password/change, TRD §4.9). Runs on every web request.
 */
final class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->must_change_password && ! $request->routeIs('password.*', 'logout', 'heartbeat')) {
            return redirect()->route('password.edit');
        }

        return $next($request);
    }
}
