<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Stays\CancelReservation;
use App\Actions\Stays\RefundNoShow;
use App\Actions\Stays\ReserveRoom;
use App\Enums\PaymentMethod;
use App\Enums\StayKind;
use App\Enums\StaySource;
use App\Exceptions\ActionRefused;
use App\Http\Middleware\EnsureDeviceKey;
use App\Http\Requests\ReserveRoomRequest;
use App\Http\Requests\SettleReservationRequest;
use App\Models\Branch;
use App\Models\Reason;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Queries\ReservationScreenQuery;
use App\Support\Clock;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reservations (57, 58 and the detail sheet, APP_FLOW §4.19, §8.1). Each write is: authorise →
 * FormRequest → one Action → redirect. Prices and availability come from
 * ReservationScreenQuery; check-in from a reservation goes through StayController (6).
 */
final class ReservationController extends Controller
{
    public function __construct(private readonly ReservationScreenQuery $screens) {}

    public function index(Request $request): Response
    {
        Gate::authorize('create', Stay::class);

        return $this->list($request, null);
    }

    /** The detail sheet over the list ("Open" on 57). */
    public function show(Request $request, Stay $stay): Response
    {
        Gate::authorize('view', $stay);

        if ($stay->source !== StaySource::Reservation) {
            abort(404);
        }

        return $this->list($request, $stay);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Stay::class);
        $branch = $this->branch($request);

        return Inertia::render('Reservations/Create', [
            ...$this->screens->create($branch),
            // The live check (partial reload of `quote` while the form is filled in).
            'quote' => fn (): ?array => $this->quote($request, $branch),
        ]);
    }

    public function store(ReserveRoomRequest $request, ReserveRoom $reserve, Clock $clock): RedirectResponse
    {
        Gate::authorize('create', Stay::class);
        $type = RoomType::query()->findOrFail($request->integer('room_type_id'));
        $room = $request->filled('room_id') ? Room::query()->findOrFail($request->integer('room_id')) : null;
        $paid = $request->integer('paid_amount');

        $stay = $reserve->handle(
            $this->user($request),
            $request->string('guest_name')->trim()->toString(),
            $request->filled('guest_mobile') ? $request->string('guest_mobile')->toString() : null,
            $type,
            $room,
            StayKind::from($request->string('kind')->toString()),
            $request->integer('quantity'),
            $this->arrival($request->string('arrival_date')->toString(), $request->string('arrival_time')->toString()),
            $request->string('idempotency_key')->toString(),
            $paid > 0 ? PaymentMethod::from($request->string('method')->toString()) : null,
            $paid > 0 ? Money::ofCentavos($paid) : null,
            $request->filled('reference_no') ? $request->string('reference_no')->trim()->toString() : null,
        );

        $tab = $stay->arrival_at !== null && $clock->toLocal($stay->arrival_at)->isSameDay($clock->local()) ? 'today' : 'upcoming';
        Inertia::flash('toast', ['type' => 'success', 'message' => "Reservation saved for {$stay->guest_name} · {$stay->code}."]);

        return redirect()->route('reservations.index', ['tab' => $tab]);
    }

    public function cancel(SettleReservationRequest $request, Stay $stay, CancelReservation $cancel): RedirectResponse
    {
        Gate::authorize('update', $stay);
        [$approver, $pin] = $this->approval($request);
        $cancel->handle($this->user($request), $stay, $this->reason($request), $approver, $pin, EnsureDeviceKey::of($request), $this->note($request), $request->boolean('refund'));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Reservation {$stay->code} cancelled."]);

        return redirect()->route('reservations.index');
    }

    public function refund(SettleReservationRequest $request, Stay $stay, RefundNoShow $refund): RedirectResponse
    {
        Gate::authorize('update', $stay);
        [$approver, $pin] = $this->approval($request);
        $refund->handle($this->user($request), $stay, $this->reason($request), $approver, $pin, EnsureDeviceKey::of($request), $this->note($request), $request->boolean('refund'));
        Inertia::flash('toast', ['type' => 'success', 'message' => $request->boolean('refund') ? "Money paid ahead on {$stay->code} handed back." : "Money paid ahead on {$stay->code} kept."]);

        return redirect()->route('reservations.index', ['tab' => 'today']);
    }

    private function list(Request $request, ?Stay $open): Response
    {
        $tab = $request->string('tab')->toString() ?: 'today';
        $date = $request->filled('date') ? $request->string('date')->toString() : null;

        return Inertia::render('Reservations/Index', [
            ...$this->screens->index($this->branch($request), $tab, $date),
            'detail' => $open === null ? null : $this->screens->detail($open, $this->user($request)),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function quote(Request $request, Branch $branch): ?array
    {
        $date = $request->string('arrival_date')->toString();
        $time = $request->string('arrival_time')->toString();
        $kind = StayKind::tryFrom($request->string('kind')->toString());

        if ($kind === null || ! $request->filled('room_type_id') || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            return null;
        }

        return $this->screens->quote(
            $branch,
            $request->integer('room_type_id'),
            $request->filled('room_id') ? $request->integer('room_id') : null,
            $kind,
            max(1, min(31, $request->integer('quantity', 1))),
            $this->arrival($date, $time),
        );
    }

    /** A local date and time on the form → the UTC instant. */
    private function arrival(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}", Clock::displayTimezone())?->utc()
            ?? throw ActionRefused::because('Type the arrival date and time.');
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
}
