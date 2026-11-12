<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use App\Queries\ShiftQuery;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Front desk routes need an open shift (APP_FLOW §2 rule 3), and a `frontdesk` user must be a
 * member of it; a supervisor works on the open shift without joining (TRD §4.9). Otherwise the
 * browser goes to /shift/open, which shows Open (2) or Join (2b).
 */
final class EnsureShiftMember
{
    public function __construct(private readonly ShiftQuery $shifts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->branch_id === null) {
            abort(403);
        }

        $shift = $this->shifts->open($user->branch_id);

        if ($shift === null || ($user->role === Role::Frontdesk && ! $this->shifts->isMember($shift->id, $user->id))) {
            return redirect()->route('shift.open');
        }

        return $next($request);
    }
}
