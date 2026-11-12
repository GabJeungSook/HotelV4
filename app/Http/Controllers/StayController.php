<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Stays\AddCharges;
use App\Actions\Stays\CancelStay;
use App\Actions\Stays\CheckInGuest;
use App\Actions\Stays\CheckOutGuest;
use App\Actions\Stays\ExtendStay;
use App\Actions\Stays\TakePayment;
use App\Actions\Stays\TransferRoom;
use App\Actions\Stays\VoidLine;
use App\Enums\PaymentMethod;
use App\Enums\RoomStatus;
use App\Enums\StayKind;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Http\Middleware\EnsureDeviceKey;
use App\Http\Requests\AddChargesRequest;
use App\Http\Requests\CancelStayRequest;
use App\Http\Requests\CheckInGuestRequest;
use App\Http\Requests\CheckOutGuestRequest;
use App\Http\Requests\ExtendStayRequest;
use App\Http\Requests\TakePaymentRequest;
use App\Http\Requests\TransferRoomRequest;
use App\Http\Requests\VoidLineRequest;
use App\Models\Discount;
use App\Models\FolioLine;
use App\Models\Reason;
use App\Models\Room;
use App\Models\Stay;
use App\Models\TransferReason;
use App\Models\User;
use App\Queries\StayScreenQuery;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The guest stay screens (6, 8–18). Each write is: authorise → FormRequest → one Action →
 * redirect (SYSTEM_DESIGN §4.1). Domain refusals are turned into dialogs and alerts by
 * DomainErrorResponse; nothing here prices, totals or allocates.
 */
final class StayController extends Controller
{
    private const SLIP_KEY = 'homi.slip';

    public function __construct(private readonly StayScreenQuery $screens) {}

    /** Walk-in check-in (6, 52, 53). */
    public function create(Request $request): Response|RedirectResponse
    {
        Gate::authorize('create', Stay::class);
        $room = Room::query()->findOrFail($request->integer('room'));

        // Coming back from a refused save, the form stays on screen under dialog 53 with the
        // guest's details; otherwise a room that is not free cannot be checked in to.
        $conflict = (Inertia::getFlashed($request)['error']['code'] ?? null) === 'room_taken';

        if (! $conflict && ($room->status !== RoomStatus::Available || $room->activeStay()->exists())) {
            Inertia::flash('error', ['code' => 'room_not_available', 'message' => "Room {$room->number} is not free for a check-in."]);

            return redirect()->route('board');
        }

        return Inertia::render('Stays/Create', $this->screens->checkIn($room));
    }

    public function store(CheckInGuestRequest $request, CheckInGuest $checkIn): RedirectResponse
    {
        Gate::authorize('create', Stay::class);
        $room = Room::query()->findOrFail($request->integer('room_id'));
        $discount = $request->filled('discount_id') ? Discount::query()->where('is_active', true)->findOrFail($request->integer('discount_id')) : null;
        $method = PaymentMethod::from($request->string('method')->toString());

        $stay = $checkIn->handle(
            $this->user($request),
            $room,
            $request->string('guest_name')->trim()->toString(),
            StayKind::from($request->string('kind')->toString()),
            $request->integer('quantity'),
            $method,
            $request->string('idempotency_key')->toString(),
            $request->filled('tendered') ? Money::ofCentavos($request->integer('tendered')) : null,
            $request->filled('reference_no') ? $request->string('reference_no')->trim()->toString() : null,
            $request->boolean('keep_change'),
            $discount,
            $request->filled('guest_mobile') ? $request->string('guest_mobile')->toString() : null,
            $request->filled('plate_number') ? mb_strtoupper($request->string('plate_number')->trim()->toString()) : null,
        );

        // The slip (8) is shown once, right after the check-in.
        $request->session()->flash(self::SLIP_KEY, $stay->id);

        return redirect()->route('stays.show', $stay);
    }

    /** The slip (8) right after check-in, otherwise the guest folio (9) with its sheets. */
    public function show(Request $request, Stay $stay): Response
    {
        Gate::authorize('view', $stay);

        if ($request->session()->get(self::SLIP_KEY) === $stay->id) {
            return Inertia::render('Stays/Slip', ['stay' => $this->screens->header($stay), 'slip' => $this->screens->slip($stay)]);
        }

        return Inertia::render('Stays/Show', $this->screens->folio($stay, $this->user($request)));
    }

    public function charge(AddChargesRequest $request, Stay $stay, AddCharges $addCharges): RedirectResponse
    {
        Gate::authorize('update', $stay);
        /** @var array<int|string, int|string> $raw */
        $raw = $request->array('items');
        $items = [];
        foreach ($raw as $id => $qty) {
            $items[(int) $id] = (int) $qty;
        }
        $payNow = $request->boolean('pay_now');

        $addCharges->handle(
            $this->user($request),
            $stay,
            $items,
            $request->string('idempotency_key')->toString(),
            $payNow ? PaymentMethod::from($request->string('method')->toString()) : null,
            $payNow ? Money::ofCentavos($request->integer('amount')) : null,
            $payNow && $request->filled('reference_no') ? $request->string('reference_no')->trim()->toString() : null,
            $payNow && $request->boolean('keep_change'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => $payNow ? 'Charge added and paid.' : 'Charge added to the folio.']);

        return redirect()->route('stays.show', $stay);
    }

    public function pay(TakePaymentRequest $request, Stay $stay, TakePayment $takePayment): RedirectResponse
    {
        Gate::authorize('update', $stay);

        $payment = $takePayment->handle(
            $this->user($request),
            $stay,
            PaymentMethod::from($request->string('method')->toString()),
            Money::ofCentavos($request->integer('amount')),
            $request->filled('reference_no') ? $request->string('reference_no')->trim()->toString() : null,
            $request->boolean('keep_change'),
            idempotencyKey: $request->string('idempotency_key')->toString(),
        );

        $change = $payment->change_amount;
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Payment saved.'.($change !== null && $change->isPositive() ? ' Change '.$change->format() : '')]);

        return redirect()->route('stays.show', $stay);
    }

    public function void(VoidLineRequest $request, Stay $stay, FolioLine $line, VoidLine $voidLine): RedirectResponse
    {
        Gate::authorize('update', $stay);

        if ($line->stay_id !== $stay->id) {
            abort(404);
        }

        [$approver, $pin] = $this->approval($request);
        $voidLine->handle($this->user($request), $line, $this->reason($request), $approver, $pin, EnsureDeviceKey::of($request), $this->note($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Voided: {$line->description}."]);

        return redirect()->route('stays.show', $stay);
    }

    public function cancel(CancelStayRequest $request, Stay $stay, CancelStay $cancelStay): RedirectResponse
    {
        Gate::authorize('update', $stay);
        [$approver, $pin] = $this->approval($request);
        $cancelStay->handle($this->user($request), $stay, $this->reason($request), $approver, $pin, EnsureDeviceKey::of($request), $this->note($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Stay {$stay->code} is cancelled. The room is available again."]);

        return redirect()->route('board');
    }

    /** Extend (12). */
    public function extend(Stay $stay): Response|RedirectResponse
    {
        Gate::authorize('update', $stay);

        if ($stay->state !== StayState::CheckedIn) {
            return redirect()->route('stays.show', $stay);
        }

        return Inertia::render('Stays/Extend', $this->screens->extend($stay));
    }

    public function extendStore(ExtendStayRequest $request, Stay $stay, ExtendStay $extendStay): RedirectResponse
    {
        Gate::authorize('update', $stay);
        $payNow = $request->boolean('pay_now');

        $extendStay->handle(
            $this->user($request),
            $stay,
            $request->integer('hours'),
            $payNow ? PaymentMethod::from($request->string('method')->toString()) : null,
            $payNow ? Money::ofCentavos($request->integer('amount')) : null,
            $payNow && $request->filled('reference_no') ? $request->string('reference_no')->trim()->toString() : null,
            $payNow && $request->boolean('keep_change'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Stay extended by '.$request->integer('hours').' hours.']);

        return redirect()->route('stays.show', $stay);
    }

    /** Transfer (13) and its approval (14). */
    public function transfer(Request $request, Stay $stay): Response|RedirectResponse
    {
        Gate::authorize('update', $stay);

        if ($stay->state !== StayState::CheckedIn) {
            return redirect()->route('stays.show', $stay);
        }

        return Inertia::render('Stays/Transfer', $this->screens->transfer($stay, $this->user($request)));
    }

    public function transferStore(TransferRoomRequest $request, Stay $stay, TransferRoom $transferRoom): RedirectResponse
    {
        Gate::authorize('update', $stay);
        $target = Room::query()->findOrFail($request->integer('room_id'));
        $reason = TransferReason::query()->where('is_active', true)->findOrFail($request->integer('reason_id'));
        [$approver, $pin] = $this->approval($request);
        $pay = $request->filled('method') && $request->filled('amount');

        $transferRoom->handle(
            $this->user($request),
            $stay,
            $target,
            $reason,
            $approver,
            $pin,
            EnsureDeviceKey::of($request),
            $request->string('old_room')->toString() === 'maintenance',
            $this->note($request),
            $pay ? PaymentMethod::from($request->string('method')->toString()) : null,
            $pay ? Money::ofCentavos($request->integer('amount')) : null,
            $pay && $request->filled('reference_no') ? $request->string('reference_no')->trim()->toString() : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Moved to Room {$target->number}."]);

        return redirect()->route('stays.show', $stay);
    }

    /**
     * Check-out (16–18). Steps 1 and 2 are page state; step 2 reloads `screen` with the step 1
     * answers so the bill and the deposit are worked out on the server. A checked-out stay
     * shows the done view (18).
     */
    public function checkout(Request $request, Stay $stay): Response|RedirectResponse
    {
        Gate::authorize('view', $stay);

        if (! in_array($stay->state, [StayState::CheckedIn, StayState::CheckedOut], true)) {
            return redirect()->route('stays.show', $stay);
        }

        $key = $request->has('key') ? $request->boolean('key') : null;
        $remote = $request->has('remote') ? $request->boolean('remote') : null;

        return Inertia::render('Stays/Checkout', [
            'screen' => fn (): array => $this->screens->checkout($stay, $key, $remote, $this->quantities($request->array('damages'))),
        ]);
    }

    public function checkoutStore(CheckOutGuestRequest $request, Stay $stay, CheckOutGuest $checkOut): RedirectResponse
    {
        Gate::authorize('update', $stay);
        $pay = $request->filled('method') && $request->filled('amount');

        $checkOut->handle(
            $this->user($request),
            $stay,
            $request->boolean('key_returned'),
            $request->boolean('remote_returned'),
            $this->quantities($request->array('damages')),
            $pay ? PaymentMethod::from($request->string('method')->toString()) : null,
            $pay ? Money::ofCentavos($request->integer('amount')) : null,
            $pay && $request->filled('reference_no') ? $request->string('reference_no')->trim()->toString() : null,
            $request->boolean('deposit_claimed'),
        );

        return redirect()->route('stays.checkout', $stay);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /** @return array{0: User, 1: string} */
    private function approval(Request $request): array
    {
        $approver = User::query()->where('is_active', true)->find($request->integer('approver_id'))
            ?? throw ActionRefused::because('Choose who approves.');

        return [$approver, $request->string('pin')->toString()];
    }

    private function reason(Request $request): Reason
    {
        return Reason::query()->where('is_active', true)->findOrFail($request->integer('reason_id'));
    }

    private function note(Request $request): ?string
    {
        return $request->filled('note') ? $request->string('note')->trim()->toString() : null;
    }

    /**
     * @param  array<int|string, mixed>  $raw
     * @return array<int, int>
     */
    private function quantities(array $raw): array
    {
        $out = [];

        foreach ($raw as $id => $qty) {
            if (is_numeric($id) && is_numeric($qty) && (int) $qty > 0) {
                $out[(int) $id] = min(99, (int) $qty);
            }
        }

        return $out;
    }
}
