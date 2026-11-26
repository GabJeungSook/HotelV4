<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Stays\HoldRoom;
use App\Actions\Stays\RequestKioskCheckout;
use App\Enums\StayKind;
use App\Http\Requests\KioskCheckoutRequest;
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
            'chosen' => [
                'room_type_id' => $request->integer('room_type_id') ?: null,
                'kind' => $request->string('kind')->toString() === 'long_stay' ? 'long_stay' : 'hourly',
                'quantity' => $request->integer('quantity') ?: ($request->integer('hours') ?: null),
            ],
            // Back from 29 after "room just taken" (APP_FLOW §4.18 step 7).
            'notice' => $request->filled('taken') ? 'Room '.mb_substr($request->string('taken')->toString(), 0, 8).' was just taken by another guest. Here is the next free room.' : null,
        ]);
    }

    /** Your name (29) for a type and stay checked on the server. */
    public function details(Request $request): Response|RedirectResponse
    {
        $kind = $request->string('kind')->toString() === 'long_stay' ? StayKind::LongStay : StayKind::Hourly;
        $quantity = $request->integer('quantity') ?: $request->integer('hours');
        $details = $this->screens->details($this->branch($request), $request->integer('room_type'), $kind, $quantity);

        if ($details === null) {
            Inertia::flash('error', ['code' => 'room_taken', 'message' => 'That room type is full right now. Please pick another.']);

            return redirect()->route('kiosk.start');
        }

        return Inertia::render('Kiosk/Details', ['offer' => $details]);
    }

    public function hold(KioskHoldRequest $request, HoldRoom $holdRoom): RedirectResponse
    {
        Gate::authorize('hold', Stay::class);
        /** @var array{room_type_id: int|string, kind?: string|null, hours?: int|string|null, days?: int|string|null, guest_name: string, guest_mobile?: string|null, discount_id?: int|string|null, idempotency_key: string} $data */
        $data = $request->validated();
        $kind = ($data['kind'] ?? null) === 'long_stay' ? StayKind::LongStay : StayKind::Hourly;
        $quantity = $kind === StayKind::LongStay ? (int) ($data['days'] ?? 0) : (int) ($data['hours'] ?? 0);

        $stay = $holdRoom->handle(
            $this->user($request),
            (int) $data['room_type_id'],
            $kind,
            $quantity,
            $data['guest_name'],
            $data['guest_mobile'] ?? null,
            $data['idempotency_key'],
            isset($data['discount_id']) ? (int) $data['discount_id'] : null,
        );

        return redirect()->route('kiosk.held', ['code' => $stay->code]);
    }

    /** Room held (30): the code, its QR, the amount and the countdown. */
    public function held(Request $request, string $code): Response
    {
        $stay = Stay::query()->where('code', $code)->where('source', 'kiosk')->firstOrFail();

        return Inertia::render('Kiosk/Held', ['hold' => $this->screens->held($stay)]);
    }

    /** Kiosk checkout (reference: HotelV2): enter room + code, review the bill, request checkout. */
    public function checkout(Request $request): Response
    {
        return Inertia::render('Kiosk/Checkout', ['bill' => null, 'room_number' => '', 'code' => '']);
    }

    /** Look up the stay by room + code and show its bill; a bad match flashes an error (handled). */
    public function checkoutLookup(KioskCheckoutRequest $request, RequestKioskCheckout $checkout): Response
    {
        $stay = $checkout->find((int) $this->user($request)->branch_id, $request->string('room_number')->toString(), $request->string('code')->toString());

        return Inertia::render('Kiosk/Checkout', [
            'bill' => $this->screens->checkoutBill($stay),
            'room_number' => $request->string('room_number')->toString(),
            'code' => mb_strtoupper(trim($request->string('code')->toString())),
        ]);
    }

    /** Flag the stay as checkout-requested; the front desk finalises it. */
    public function checkoutConfirm(KioskCheckoutRequest $request, RequestKioskCheckout $checkout): RedirectResponse
    {
        $checkout->handle($this->user($request), $request->string('room_number')->toString(), $request->string('code')->toString());

        return redirect()->route('kiosk.checkout.done');
    }

    public function checkoutDone(): Response
    {
        return Inertia::render('Kiosk/CheckoutDone');
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
