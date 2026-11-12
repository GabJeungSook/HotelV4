<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Stays\HoldRoom;
use App\Http\Requests\KioskHoldRequest;
use App\Models\Branch;
use App\Models\Stay;
use App\Models\User;
use App\Queries\KioskScreenQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The lobby kiosk (27–31b, APP_FLOW §4.18): welcome, room and hours, your name, room held. The
 * kiosk device signs in once for its branch. Every price and room comes from KioskScreenQuery;
 * the only write is HoldRoom, which is sent a type, hours, a name, a mobile and a key.
 */
final class KioskController extends Controller
{
    public function __construct(private readonly KioskScreenQuery $screens) {}

    /** Welcome (27); no rooms (31) when nothing is free. The page reloads this every 20 s. */
    public function index(Request $request): Response
    {
        return Inertia::render('Kiosk/Welcome', $this->screens->welcome($this->branch($request)));
    }

    /** Room and hours (28). The choice comes back in the query string from Back on 29. */
    public function start(Request $request): Response
    {
        $props = $this->screens->start($this->branch($request));

        return Inertia::render('Kiosk/PickRoom', [
            ...$props,
            'chosen' => ['room_type_id' => $request->integer('room_type_id') ?: null, 'hours' => $request->integer('hours') ?: null],
            // Back from 29 after "room just taken" (APP_FLOW §4.18 step 7).
            'notice' => $request->filled('taken') ? 'Room '.mb_substr($request->string('taken')->toString(), 0, 8).' was just taken by another guest. Here is the next free room.' : null,
        ]);
    }

    /** Your name (29) for a type and hours checked on the server. */
    public function details(Request $request): Response|RedirectResponse
    {
        $details = $this->screens->details($this->branch($request), $request->integer('room_type'), $request->integer('hours'));

        if ($details === null) {
            Inertia::flash('error', ['code' => 'room_taken', 'message' => 'That room type is full right now. Please pick another.']);

            return redirect()->route('kiosk.start');
        }

        return Inertia::render('Kiosk/Details', ['offer' => $details]);
    }

    public function hold(KioskHoldRequest $request, HoldRoom $holdRoom): RedirectResponse
    {
        Gate::authorize('hold', Stay::class);
        /** @var array{room_type_id: int|string, hours: int|string, guest_name: string, guest_mobile?: string|null, idempotency_key: string} $data */
        $data = $request->validated();

        $stay = $holdRoom->handle(
            $this->user($request),
            (int) $data['room_type_id'],
            (int) $data['hours'],
            $data['guest_name'],
            $data['guest_mobile'] ?? null,
            $data['idempotency_key'],
        );

        return redirect()->route('kiosk.held', ['code' => $stay->code]);
    }

    /** Room held (30): the code, its QR, the amount and the countdown. */
    public function held(Request $request, string $code): Response
    {
        $stay = Stay::query()->where('code', $code)->where('source', 'kiosk')->firstOrFail();

        return Inertia::render('Kiosk/Held', ['hold' => $this->screens->held($stay)]);
    }

    private function branch(Request $request): Branch
    {
        return Branch::query()->findOrFail($this->user($request)->branch_id);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
