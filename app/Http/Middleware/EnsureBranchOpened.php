<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\CurrentBranch;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Branch setup (44–48) works on one branch (TRD §4.1): an admin's own, or the one an owner
 * opened from the branches list (55). An owner on "All branches" is sent to that list to open
 * one; before the list exists, the no-permission page (51).
 */
final class EnsureBranchOpened
{
    public function __construct(private readonly CurrentBranch $branches) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->branches->id() === null) {
            if (Route::has('owner.branches.index')) {
                return redirect()->route('owner.branches.index');
            }

            abort(403);
        }

        return $next($request);
    }
}
