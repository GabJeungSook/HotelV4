<?php

declare(strict_types=1);

namespace App\Actions\Owner;

use App\Enums\BranchStatus;
use App\Enums\Role;
use App\Exceptions\ActionRefused;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Support\Clock;
use App\Support\Transaction;

/**
 * Deactivate a branch, or set an inactive one back to active (screen 55, APP_FLOW §5.6, TRD §4.9
 * "Inactive branch"). A branch is never deleted, so its history stays readable. Only
 * `active → inactive` and `inactive → active` are allowed here; a branch still setting up
 * becomes active when its first room is saved (44). Writes audit_logs.
 */
final class SetBranchStatus
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $owner, int $branchId, BranchStatus $to): Branch
    {
        if ($owner->role !== Role::Owner) {
            throw ActionRefused::because('Only an owner can change a branch status.');
        }

        return $this->transaction->run(function () use ($owner, $branchId, $to): Branch {
            $branch = Branch::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($branchId);
            $from = $branch->status;

            if ($from === $to) {
                return $branch;
            }

            $allowed = ($from === BranchStatus::Active && $to === BranchStatus::Inactive)
                || ($from === BranchStatus::Inactive && $to === BranchStatus::Active);

            if (! $allowed) {
                throw ActionRefused::because($from === BranchStatus::SettingUp
                    ? "{$branch->name} is still being set up. It becomes active when its first room is added."
                    : "{$branch->name} cannot go from {$from->label()} to {$to->label()}.");
            }

            $branch->update(['status' => $to]);

            AuditLog::query()->create([
                'branch_id' => $branch->id,
                'actor_id' => $owner->id,
                'action' => $to === BranchStatus::Inactive ? 'branch.deactivated' : 'branch.reactivated',
                'subject_type' => 'branch',
                'subject_id' => $branch->id,
                'before_json' => ['status' => $from->value],
                'after_json' => ['status' => $to->value],
                'created_at' => $this->clock->now(),
            ]);

            return $branch;
        });
    }
}
