<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A front desk, supervisor or admin user without a PIN sets one before anything else
 * (APP_FLOW §4.1 step 7, §8.1 `/pin/setup`).
 */
final class EnsurePinSet
{
    public const ROLES = [Role::Frontdesk, Role::Supervisor, Role::Admin];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && self::needsPin($user) && ! $request->routeIs('pin.*', 'logout', 'heartbeat')) {
            return redirect()->route('pin.edit');
        }

        return $next($request);
    }

    public static function needsPin(User $user): bool
    {
        return in_array($user->role, self::ROLES, true) && ($user->pin_hash === null || $user->pin_hash === '');
    }
}
