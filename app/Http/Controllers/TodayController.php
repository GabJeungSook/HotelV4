<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Queries\TodayQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Front desk Today (62): live counts and names only, never money (TRD §4.11). */
final class TodayController extends Controller
{
    public function index(Request $request, TodayQuery $today): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Dashboard/Today', [
            'today' => fn (): array => $today->today((int) $user->branch_id),
            'poll' => config('hotel.polling.dashboard'),
        ]);
    }
}
