<?php

declare(strict_types=1);

namespace App\Actions\Shifts;

use App\Actions\Auth\ApproveByPin;
use App\Enums\ApprovalAction;
use App\Enums\CashMovementKind;
use App\Enums\ShiftStatus;
use App\Exceptions\ActionRefused;
use App\Exceptions\NotEnoughCash;
use App\Models\CashMovement;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\ShiftSession;
use App\Models\User;
use App\Services\CashMath;
use App\Services\CurrentShift;
use App\Support\Clock;
use App\Support\DuplicateKey;
use App\Support\Money;
use App\Support\Transaction;
use Illuminate\Database\QueryException;

/**
 * An expense, a remittance or a shortage payment on the open shift (screen 21, APP_FLOW §4.15,
 * DATABASE_SCHEMA §4.9, TRD §4.12).
 *
 * - The form's idempotency key goes on the row: a repeated key returns the first row and writes
 *   nothing (a double tap saves one entry).
 * - Every entry needs an amount above ₱0.00 and a note. An expense needs an active category of
 *   the branch; its needs_note and needs_manager flags are enforced here again, needs_manager
 *   with a supervisor PIN (approvals.action = reason_requires_manager).
 * - A shortage payment names an earlier closed shift of the branch that was short.
 * - An expense or remittance above the cash expected in the drawer at that moment is refused
 *   with NotEnoughCash, whose message never states the amount.
 * - ShiftClosing once a member has started the close.
 */
final class RecordCashMovement
{
    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly CashMath $cash,
        private readonly ApproveByPin $approveByPin,
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    public function handle(
        User $by,
        int $branchId,
        CashMovementKind $kind,
        Money $amount,
        string $note,
        string $idempotencyKey,
        ?ExpenseCategory $category = null,
        ?int $forShiftId = null,
        ?User $approver = null,
        ?string $approverPin = null,
        ?string $deviceKey = null,
    ): CashMovement {
        $existing = $this->replay($idempotencyKey);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $this->transaction->run(function () use ($by, $branchId, $kind, $amount, $note, $idempotencyKey, $category, $forShiftId, $approver, $approverPin, $deviceKey): CashMovement {
                $shift = $this->currentShift->forWrite($branchId);
                $note = trim($note);

                if (! $amount->isPositive()) {
                    throw ActionRefused::because('Type an amount above ₱0.00.');
                }

                if ($note === '') {
                    throw ActionRefused::noteRequired('every cash entry');
                }

                if ($kind === CashMovementKind::Expense) {
                    $this->assertCategory($category, $branchId);
                } else {
                    $category = null;
                }

                if ($kind === CashMovementKind::ShortagePayment) {
                    $this->assertShortShift($forShiftId, $branchId);
                } else {
                    $forShiftId = null;
                }

                if ($kind !== CashMovementKind::ShortagePayment && $amount->greaterThan($this->expectedInDrawer($shift))) {
                    throw NotEnoughCash::make();
                }

                $movement = CashMovement::query()->create([
                    'branch_id' => $branchId,
                    'shift_session_id' => $shift->id,
                    'kind' => $kind,
                    'expense_category_id' => $category?->id,
                    'for_shift_id' => $forShiftId,
                    'amount' => $amount,
                    'note' => mb_substr($note, 0, 255),
                    'created_by' => $by->id,
                    'occurred_at' => $this->clock->now(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                if ($category?->needs_manager) {
                    if ($approver === null || $approverPin === null || $deviceKey === null) {
                        throw ActionRefused::because("{$category->name} needs a supervisor PIN.");
                    }

                    $this->approveByPin->handle(
                        $approver, $approverPin, $deviceKey, $by, $branchId,
                        ApprovalAction::ReasonRequiresManager, 'cash_movement', $movement->id,
                        $category->name, "Expense · {$note} · −".$amount->format(),
                    );
                }

                return $movement;
            });
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'cash_movements_idempotency_unique')) {
                return $this->replay($idempotencyKey) ?? throw $e;
            }

            throw $e;
        }
    }

    /** The cash expected in the drawer right now (TRD §4.5). Never leaves this class. */
    private function expectedInDrawer(ShiftSession $shift): Money
    {
        return $this->cash->expectedFromRows(
            $shift->opening_cash ?? Money::zero(),
            Payment::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->get(),
            CashMovement::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->get(),
        );
    }

    private function assertCategory(?ExpenseCategory $category, int $branchId): void
    {
        if ($category === null || $category->branch_id !== $branchId || ! $category->is_active) {
            throw ActionRefused::because('Pick an expense category.');
        }
    }

    private function assertShortShift(?int $forShiftId, int $branchId): void
    {
        $short = $forShiftId === null ? null : ShiftSession::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('status', ShiftStatus::Closed)
            ->where('variance_amount', '<', 0)
            ->find($forShiftId);

        if ($short === null) {
            throw ActionRefused::because('Pick the shift that was short.');
        }
    }

    private function replay(string $idempotencyKey): ?CashMovement
    {
        return CashMovement::query()->withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();
    }
}
