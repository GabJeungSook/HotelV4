<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `role:frontdesk,supervisor` on a route group (TRD §4.1). Any other role gets HTTP 403, which
 * renders the no-permission page (51). Policies then guard the record itself.
 */
final class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! in_array($user->role->value, $roles, true) || ! $user->is_active) {
            abort(403);
        }

        return $next($request);
    }

    public static function using(Role ...$roles): string
    {
        return 'role:'.implode(',', array_map(static fn (Role $r): string => $r->value, $roles));
    }
}
