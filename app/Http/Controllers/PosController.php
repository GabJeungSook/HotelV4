<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Pos\CompleteSale;
use App\Actions\Pos\VoidSale;
use App\Enums\PaymentKind;
use App\Enums\PosMode;
use App\Exceptions\ActionRefused;
use App\Exceptions\NoOpenShift;
use App\Http\Middleware\EnsureDeviceKey;
use App\Http\Requests\PosSaleRequest;
use App\Http\Requests\VoidSaleRequest;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\Reason;
use App\Models\Stay;
use App\Models\User;
use App\Queries\PosScreenQuery;
use App\Queries\ShiftQuery;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The point of sale (19, APP_FLOW §4.13): walk-in cash sales and charges to a room, and the
 * void of a sale of this shift with a supervisor PIN (14). Thin: authorise → request → action →
 * redirect. Domain refusals reach the page as flash.error through DomainErrorResponse.
 */
final class PosController extends Controller
{
    public function __construct(private readonly ShiftQuery $shifts) {}

    public function index(Request $request, PosScreenQuery $screens): Response
    {
        Gate::authorize('create', PosOrder::class);
        $user = $this->user($request);
        $shift = $this->shifts->open((int) $user->branch_id) ?? throw NoOpenShift::make();

        return Inertia::render('Pos/Index', $screens->pos($shift, $user));
    }

    public function store(PosSaleRequest $request, CompleteSale $sale): RedirectResponse
    {
        Gate::authorize('create', PosOrder::class);
        $user = $this->user($request);
        $mode = PosMode::from($request->string('mode')->toString());

        /** @var array<int|string, int|string> $raw */
        $raw = $request->array('items');
        $items = [];
        foreach ($raw as $id => $qty) {
            $items[(int) $id] = (int) $qty;
        }

        $stay = null;
        if ($mode === PosMode::RoomCharge) {
            $stay = Stay::query()->find($request->integer('stay_id')) ?? throw ActionRefused::because('Pick the occupied room to charge.');
            Gate::authorize('update', $stay);
        }

        $order = $sale->handle(
            $user,
            (int) $user->branch_id,
            $mode,
            $items,
            $request->string('idempotency_key')->toString(),
            $mode === PosMode::WalkIn ? Money::ofCentavos($request->integer('tendered')) : null,
            $stay,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->savedMessage($order)]);

        return redirect()->route('pos.index');
    }

    public function void(VoidSaleRequest $request, PosOrder $order, VoidSale $void): RedirectResponse
    {
        Gate::authorize('void', $order);
        $approver = User::query()->where('is_active', true)->find($request->integer('approver_id'))
            ?? throw ActionRefused::because('Choose who approves.');
        $reason = Reason::query()->where('is_active', true)->findOrFail($request->integer('reason_id'));

        $void->handle(
            $this->user($request), $order, $reason, $approver,
            $request->string('pin')->toString(), EnsureDeviceKey::of($request),
            $request->filled('note') ? $request->string('note')->trim()->toString() : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sale {$order->order_no} is voided. The stock is back."]);

        return redirect()->route('pos.index');
    }

    private function savedMessage(PosOrder $order): string
    {
        $total = $order->total_amount?->format() ?? '';

        if ($order->mode === PosMode::RoomCharge) {
            $room = $order->stay()->withoutGlobalScopes()->with(['room' => fn ($q) => $q->withoutGlobalScopes()])->first()?->room?->number;

            return "{$total} charged to Room {$room}.";
        }

        $change = Payment::query()->where('pos_order_id', $order->id)->where('kind', PaymentKind::Payment)->first()->change_amount ?? Money::zero();

        return "Sale {$order->order_no} saved. Change ".$change->format();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
