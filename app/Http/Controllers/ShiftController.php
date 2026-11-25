<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\SignOutShiftTeam;
use App\Actions\Reports\LogPrint;
use App\Actions\Shifts\CloseShift;
use App\Actions\Shifts\HandOverShift;
use App\Actions\Shifts\JoinShift;
use App\Actions\Shifts\OpenShift;
use App\Actions\Shifts\RecordCashMovement;
use App\Actions\Shifts\SaveCountDraft;
use App\Actions\Shifts\VoidCashMovement;
use App\Enums\CashMovementKind;
use App\Enums\PrintDocument;
use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Exceptions\ActionRefused;
use App\Exceptions\DomainException;
use App\Exceptions\NoOpenShift;
use App\Http\Middleware\EnsureDeviceKey;
use App\Http\Requests\CashMovementRequest;
use App\Http\Requests\CloseShiftRequest;
use App\Http\Requests\OpenShiftRequest;
use App\Http\Requests\VoidCashMovementRequest;
use App\Models\ExpenseCategory;
use App\Models\Reason;
use App\Models\ShiftSession;
use App\Models\User;
use App\Queries\ShiftQuery;
use App\Queries\ShiftScreenQuery;
use App\Queries\StayScreenQuery;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shift screens (APP_FLOW §2): open (2) and join (2b), shift cash (21), the close guide
 * (22–25) and the shift report slip (26). Thin: each write is one Action; the props come from
 * ShiftQuery and ShiftScreenQuery, which keep the count blind (no expected cash before the
 * count is saved).
 */
final class ShiftController extends Controller
{
    public function __construct(private readonly ShiftQuery $shifts) {}

    public function create(Request $request, StayScreenQuery $screens): Response|RedirectResponse
    {
        $user = $this->user($request);
        $branchId = (int) $user->branch_id;
        $open = $this->shifts->open($branchId);

        // Members, and supervisors while a shift is open, have nothing to do here.
        if ($open !== null && ($user->role === Role::Supervisor || $this->shifts->isMember($open->id, $user->id))) {
            return redirect()->route('today');
        }

        $screen = $this->shifts->openScreen($branchId);

        return Inertia::render($screen['mode'] === 'join' ? 'Shift/Join' : 'Shift/Open', [
            ...$screen,
            'approvers' => $screen['mode'] === 'open' ? $screens->approvers($branchId, $user) : [],
            'user' => $user->name,
        ]);
    }

    public function store(OpenShiftRequest $request, OpenShift $open): RedirectResponse
    {
        $user = $this->user($request);
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
        $user = $this->user($request);
        $join->handle($user, (int) $user->branch_id);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'You joined the shift.']);

        return redirect()->route('today');
    }

    /** Shift cash (21). */
    public function show(Request $request, ShiftScreenQuery $screens): Response
    {
        $user = $this->user($request);

        return Inertia::render('Shift/Cash', $screens->cash($this->openShift($user), $user));
    }

    public function movement(CashMovementRequest $request, RecordCashMovement $record): RedirectResponse
    {
        $user = $this->user($request);
        $kind = CashMovementKind::from($request->string('kind')->toString());
        $category = $kind === CashMovementKind::Expense ? ExpenseCategory::query()->find($request->integer('expense_category_id')) : null;
        $approver = $request->filled('approver_id') ? User::query()->where('is_active', true)->find($request->integer('approver_id')) : null;
        $amount = Money::ofCentavos($request->integer('amount'));

        $record->handle(
            $user,
            (int) $user->branch_id,
            $kind,
            $amount,
            $request->string('note')->toString(),
            $request->string('idempotency_key')->toString(),
            $category,
            $kind === CashMovementKind::ShortagePayment ? $request->integer('for_shift_id') : null,
            $approver,
            $request->filled('pin') ? $request->string('pin')->toString() : null,
            $approver === null ? null : EnsureDeviceKey::of($request),
        );

        $label = match ($kind) {
            CashMovementKind::Expense => 'Expense',
            CashMovementKind::Remittance => 'Remittance',
            CashMovementKind::ShortagePayment => 'Shortage payment',
        };
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$label} of {$amount->format()} saved."]);

        return redirect()->route('shift.show');
    }

    public function voidMovement(VoidCashMovementRequest $request, int $movement, VoidCashMovement $void): RedirectResponse
    {
        $user = $this->user($request);
        $approver = User::query()->where('is_active', true)->find($request->integer('approver_id'))
            ?? throw ActionRefused::because('Choose who approves.');
        $reason = Reason::query()->where('is_active', true)->findOrFail($request->integer('reason_id'));

        $void->handle(
            $user, (int) $user->branch_id, $movement, $reason,
            $request->filled('note') ? $request->string('note')->trim()->toString() : null,
            $approver, $request->string('pin')->toString(), EnsureDeviceKey::of($request),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'The entry is voided. Type the correct one again if needed.']);

        return redirect()->route('shift.show');
    }

    /** The close guide (22–25): the step shown comes from the database. */
    public function close(Request $request, ShiftScreenQuery $screens): Response
    {
        $user = $this->user($request);

        return Inertia::render('Shift/Close', $screens->close($this->openShift($user), $user));
    }

    /** One step of the close at a time, named by `step` (APP_FLOW §2: closeStore). */
    public function closeStore(CloseShiftRequest $request, CloseShift $close, HandOverShift $handOver): RedirectResponse
    {
        $user = $this->user($request);
        $branchId = (int) $user->branch_id;

        switch ($request->string('step')->toString()) {
            case 'begin':
                $close->begin($user, $branchId);

                return redirect()->route('shift.close');

            case 'not_yet':
                $close->notYet($user, $branchId);
                Inertia::flash('toast', ['type' => 'info', 'message' => 'The shift is open again.']);

                return redirect()->route('today');

            case 'count':
                $pieces = $request->input('pieces');
                $witness = $request->filled('witness_id') ? User::query()->find($request->integer('witness_id')) : null;
                $total = $request->filled('counted') ? Money::ofCentavos($request->integer('counted')) : null;
                $close->count($user, $branchId, is_array($pieces) ? $pieces : [], $witness, $total);

                return redirect()->route('shift.close');

            case 'result':
                $reason = $request->filled('reason_id') ? Reason::query()->where('is_active', true)->find($request->integer('reason_id')) : null;
                $close->result($user, $branchId, $reason, $request->filled('note') ? $request->string('note')->toString() : null);

                return redirect()->route('shift.close');

            case 'handover':
                $closing = $this->openShift($user)->id;
                $receiver = User::query()->where('is_active', true)->find($request->integer('receiver_id'))
                    ?? throw ActionRefused::because('Pick the incoming front desk.');
                $next = $handOver->toReceiver(
                    $user, $branchId, $receiver, $request->string('pin')->toString(),
                    Money::ofCentavos($request->integer('received_cash')), EnsureDeviceKey::of($request),
                    $request->filled('handover_note') ? $request->string('handover_note')->toString() : null,
                    $request->filled('difference_note') ? $request->string('difference_note')->toString() : null,
                );
                app(SignOutShiftTeam::class)->handle($closing, [$receiver->id, $user->id]);
                Inertia::flash('toast', ['type' => 'success', 'message' => "The shift is closed. {$receiver->name} opened the {$next->period_name} shift."]);

                return redirect()->route('shift.report', $closing);

            case 'without_handover':
                $approver = User::query()->where('is_active', true)->find($request->integer('approver_id'))
                    ?? throw ActionRefused::because('Choose who approves.');
                $closed = $handOver->withoutReceiver(
                    $user, $branchId, $approver, $request->string('pin')->toString(), EnsureDeviceKey::of($request),
                    $request->filled('handover_note') ? $request->string('handover_note')->toString() : null,
                );
                app(SignOutShiftTeam::class)->handle($closed->id, [$user->id]);
                Inertia::flash('toast', ['type' => 'success', 'message' => 'The shift is closed without handover. The next front desk uses Open shift.']);

                return redirect()->route('shift.report', $closed->id);
        }

        throw ActionRefused::because('Unknown step.');
    }

    /** PUT /shift/close/draft: the pieces typed so far (23). Answers with JSON, never a page. */
    public function closeDraft(Request $request, SaveCountDraft $save): JsonResponse
    {
        $user = $this->user($request);
        $pieces = $request->input('pieces');

        if (! is_array($pieces)) {
            return response()->json(['message' => 'No pieces sent.'], 422);
        }

        try {
            $save->handle($user, (int) $user->branch_id, $pieces);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->userMessage()], 409);
        }

        return response()->json(['saved' => true]);
    }

    /** Shift report slip (26) of a closed shift: supervisors, and front desks who were members. */
    public function report(Request $request, ShiftSession $shift, ShiftScreenQuery $screens): Response
    {
        $this->authorizeReport($request, $shift);

        return Inertia::render('Shift/ReportSlip', $screens->report($shift));
    }

    /** Logs one print of slip 26 before the browser prints it, and says whether it is a reprint. */
    public function reportPrint(Request $request, ShiftSession $shift, LogPrint $log): JsonResponse
    {
        $this->authorizeReport($request, $shift);
        $row = $log->handle($this->user($request), $shift->branch_id, PrintDocument::ShiftSlip, $shift->id);

        return response()->json(['reprint' => $row->is_reprint]);
    }

    private function authorizeReport(Request $request, ShiftSession $shift): void
    {
        $user = $this->user($request);

        if ($shift->branch_id !== $user->branch_id || $shift->status !== ShiftStatus::Closed) {
            abort(404);
        }

        if ($user->role !== Role::Supervisor && ! $this->shifts->isMember($shift->id, $user->id)) {
            abort(403);
        }
    }

    private function openShift(User $user): ShiftSession
    {
        return $this->shifts->open((int) $user->branch_id) ?? throw NoOpenShift::make();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
