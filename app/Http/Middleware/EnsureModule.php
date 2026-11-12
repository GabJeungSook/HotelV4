<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\CurrentBranch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `module:pos_enabled`: a module switched off for the branch is refused with 51 (APP_FLOW §2 rule 1). */
final class EnsureModule
{
    public function __construct(private readonly CurrentBranch $branches) {}

    public function handle(Request $request, Closure $next, string $flag): Response
    {
        $branch = $this->branches->branch();

        if ($branch === null || ! (bool) $branch->getAttribute($flag)) {
            abort(403);
        }

        return $next($request);
    }
}
