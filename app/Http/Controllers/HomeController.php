<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Queries\ShiftQuery;
use App\Services\CurrentBranch;
use App\Support\Navigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Sends a signed-in user to their role home (APP_FLOW §1.1, §4.1 step 8). */
final class HomeController extends Controller
{
    public function __invoke(Request $request, ShiftQuery $shifts, CurrentBranch $branches): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $shift = $user->branch_id === null ? null : $shifts->open($user->branch_id);
        $member = $shift !== null && $shifts->isMember($shift->id, $user->id);

        return redirect(Navigation::home($user, $branches->branch(), $member));
    }
}
