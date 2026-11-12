<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Queries\BoardQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The room board (4, 4b, 4c): BoardQuery, refreshed every 15 s by a partial reload of `board`. */
final class BoardController extends Controller
{
    public function index(Request $request, BoardQuery $board): Response
    {
        /** @var User $user */
        $user = $request->user();
        $q = mb_substr(trim($request->string('q')->toString()), 0, 40);
        $floor = $request->string('floor')->toString();

        return Inertia::render('Board/Index', [
            'board' => Inertia::defer(fn (): array => $board->forBranch((int) $user->branch_id, $q, $floor)),
            'filters' => ['q' => $q, 'floor' => $floor],
            'poll' => config('hotel.polling.board'),
        ]);
    }
}
