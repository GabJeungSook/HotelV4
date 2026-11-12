<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Shifts\JoinShift;
use App\Actions\Shifts\OpenShift;
use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceKey;
use App\Http\Requests\OpenShiftRequest;
use App\Models\User;
use App\Queries\ShiftQuery;
use App\Queries\StayScreenQuery;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Open shift (2) and join shift (2b), APP_FLOW §4.2 and §4.3. The rest of the shift (21–26)
 * is Phase 5.
 */
final class ShiftController extends Controller
{
    public function create(Request $request, ShiftQuery $shifts, StayScreenQuery $screens): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $branchId = (int) $user->branch_id;
        $open = $shifts->open($branchId);

        // Members, and supervisors while a shift is open, have nothing to do here.
        if ($open !== null && ($user->role === Role::Supervisor || $shifts->isMember($open->id, $user->id))) {
            return redirect()->route('today');
        }

        $screen = $shifts->openScreen($branchId);

        return Inertia::render($screen['mode'] === 'join' ? 'Shift/Join' : 'Shift/Open', [
            ...$screen,
            'approvers' => $screen['mode'] === 'open' ? $screens->approvers($branchId, $user) : [],
            'user' => $user->name,
        ]);
    }

    public function store(OpenShiftRequest $request, OpenShift $open): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $approver = $request->filled('approver_id') ? User::query()->find($request->integer('approver_id')) : null;

        $shift = $open->handle(
            $user,
            (int) $user->branch_id,
            Money::ofCentavos($request->integer('opening_cash')),
            $request->filled('note') ? $request->string('note')->toString() : null,
            $request->filled('definition_id') ? $request->integer('definition_id') : null,
            $request->filled('business_date') ? $request->string('business_date')->toString() : null,
            $approver,
            $request->filled('pin') ? $request->string('pin')->toString() : null,
            $approver === null ? null : EnsureDeviceKey::of($request),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "The {$shift->period_name} shift is open."]);

        return redirect()->route('today');
    }

    public function join(Request $request, JoinShift $join): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $join->handle($user, (int) $user->branch_id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'You joined the shift.']);

        return redirect()->route('today');
    }
}
