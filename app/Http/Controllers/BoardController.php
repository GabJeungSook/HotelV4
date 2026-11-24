<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\StayState;
use App\Models\Stay;
use App\Models\User;
use App\Queries\BoardQuery;
use App\Queries\HoldScreenQuery;
use App\Queries\KioskOrderQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The room board (4, 4b, 4c): BoardQuery, refreshed every 15 s by a partial reload of `board`;
 * the kiosk holds sheet (5) reloads `holds` every 5 s while it is open. A scanned hold code
 * (the QR on 30 types "DGS-26-004127" into the search) opens the confirm screen (7).
 */
final class BoardController extends Controller
{
    public function index(Request $request, BoardQuery $board, HoldScreenQuery $holds, KioskOrderQuery $kioskOrder): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $q = mb_substr(trim($request->string('q')->toString()), 0, 40);
        $floor = $request->string('floor')->toString();

        if (preg_match('/^[A-Z0-9]{2,6}-\d{2}-\d{6}$/i', $q) === 1) {
            $hold = Stay::query()->where('code', mb_strtoupper($q))->where('state', StayState::Held)->first();

            if ($hold !== null) {
                return redirect()->route('stays.confirm', $hold);
            }
        }

        return Inertia::render('Board/Index', [
            'board' => Inertia::defer(fn (): array => $board->forBranch((int) $user->branch_id, $q, $floor)),
            'holds' => fn (): array => $holds->holds((int) $user->branch_id),
            'kioskOrder' => Inertia::defer(fn (): array => $kioskOrder->forBranch((int) $user->branch_id)),
            'filters' => ['q' => $q, 'floor' => $floor],
            'poll' => config('hotel.polling.board'),
            'holdsPoll' => config('hotel.polling.kiosk_holds'),
        ]);
    }
}
