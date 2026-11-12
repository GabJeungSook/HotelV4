<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\ApprovalAction;
use App\Enums\PinContext;
use App\Enums\Role;
use App\Exceptions\ApprovalRefused;
use App\Exceptions\PinLocked;
use App\Models\Approval;
use App\Models\User;
use App\Services\PinGuard;
use App\Support\Clock;
use App\Support\Transaction;

/**
 * A supervisor approves an action with their PIN (sheet 14). The approver must be another
 * person than the requester, a supervisor or admin of the branch, or an owner, and active
 * (TRD §9). The try is logged in pin_attempts; five wrong PINs lock that approver for 15
 * minutes; a locked computer refuses every PIN (TRD §4.12).
 *
 * Called inside the action that needs the approval, so the approvals row and the action
 * roll back together (APP_FLOW §6 case 38). The try itself is kept either way.
 */
final class ApproveByPin
{
    public function __construct(
        private readonly PinGuard $pins,
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    public function handle(
        User $approver,
        string $pin,
        string $deviceKey,
        User $requester,
        int $branchId,
        ApprovalAction $action,
        string $subjectType,
        int $subjectId,
        string $reason,
        string $summary,
        ?int $reasonId = null,
    ): Approval {
        return $this->transaction->run(function () use ($approver, $pin, $deviceKey, $requester, $branchId, $action, $subjectType, $subjectId, $reason, $summary, $reasonId): Approval {
            $this->pins->assertUnlocked($deviceKey);

            if ($approver->id === $requester->id) {
                throw ApprovalRefused::selfApproval();
            }

            if (! $this->mayApprove($approver, $branchId)) {
                throw ApprovalRefused::notAllowed($approver->name);
            }

            if ($this->pins->approverLocked($approver)) {
                throw PinLocked::approver($approver->name, $this->pins->approverLockMinutes());
            }

            $this->pins->check($approver, $pin, $deviceKey, PinContext::Approval, $branchId, $this->pins->approverLimit());

            return Approval::query()->create([
                'branch_id' => $branchId,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'requested_by' => $requester->id,
                'approved_by' => $approver->id,
                'reason_id' => $reasonId,
                'reason' => mb_substr($reason, 0, 255),
                'summary' => mb_substr($summary, 0, 255),
                'created_at' => $this->clock->now(),
            ]);
        });
    }

    private function mayApprove(User $approver, int $branchId): bool
    {
        if (! $approver->is_active) {
            return false;
        }

        return $approver->role === Role::Owner
            || (in_array($approver->role, [Role::Supervisor, Role::Admin], true) && $approver->branch_id === $branchId);
    }
}
