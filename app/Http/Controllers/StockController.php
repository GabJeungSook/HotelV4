<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Pos\ReceiveStock;
use App\Actions\Pos\RecordWastage;
use App\Exceptions\NoOpenShift;
use App\Http\Middleware\EnsureDeviceKey;
use App\Http\Requests\StockRequest;
use App\Models\Reason;
use App\Models\StockMovement;
use App\Models\User;
use App\Queries\PosScreenQuery;
use App\Queries\ShiftQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock (20, APP_FLOW §4.14): this shift's opening, in, sold, wastage and on hand per product,
 * and the two forms that write stock_movements — receive stock and record wastage.
 */
final class StockController extends Controller
{
    public function __construct(private readonly ShiftQuery $shifts) {}

    public function index(Request $request, PosScreenQuery $screens): Response
    {
        Gate::authorize('viewAny', StockMovement::class);
        $user = $this->user($request);
        $shift = $this->shifts->open((int) $user->branch_id) ?? throw NoOpenShift::make();

        return Inertia::render('Stock/Index', $screens->stock($shift, $user));
    }

    public function store(StockRequest $request, ReceiveStock $receive, RecordWastage $wastage): RedirectResponse
    {
        Gate::authorize('create', StockMovement::class);
        $user = $this->user($request);
        $branchId = (int) $user->branch_id;
        $note = $request->filled('note') ? $request->string('note')->trim()->toString() : null;

        if ($request->string('kind')->toString() === 'receive') {
            $movement = $receive->handle($user, $branchId, $request->integer('product_id'), $request->integer('quantity'), $note);
            $message = "{$movement->quantity} received. {$movement->balance_after} on hand.";
        } else {
            $approver = $request->filled('approver_id') ? User::query()->where('is_active', true)->find($request->integer('approver_id')) : null;
            $movement = $wastage->handle(
                $user, $branchId, $request->integer('product_id'), $request->integer('quantity'),
                Reason::query()->where('is_active', true)->findOrFail($request->integer('reason_id')),
                (string) $note,
                $approver,
                $request->filled('pin') ? $request->string('pin')->toString() : null,
                $approver === null ? null : EnsureDeviceKey::of($request),
            );
            $message = 'Wastage of '.abs($movement->quantity)." saved. {$movement->balance_after} on hand.";
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->route('stock.index');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
