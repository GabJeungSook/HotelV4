<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Exceptions\ActionRefused;
use App\Models\User;
use App\Services\AuditTrail;
use App\Support\Transaction;

/**
 * Deactivate or reactivate a user of this branch (47). Staff who leave are deactivated, never
 * deleted, so their past work keeps their name; a deactivated user cannot sign in or pass the
 * role check. You cannot deactivate yourself. One transaction with its audit_logs row
 * ('user.deactivated' or 'user.reactivated').
 */
final class SetUserActive
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $by, int $branchId, int $userId, bool $active): User
    {
        return $this->transaction->run(function () use ($by, $branchId, $userId, $active): User {
            $user = SaveUser::branchUser($branchId, $userId, lock: true);

            if ($user->id === $by->id && ! $active) {
                throw ActionRefused::because('You cannot deactivate your own account.');
            }

            if ($user->is_active === $active) {
                return $user;
            }

            $user->update(['is_active' => $active]);
            $this->audit->record($by, $branchId, $active ? 'user.reactivated' : 'user.deactivated', 'user', $user->id, ['is_active' => ! $active], ['is_active' => $active]);

            return $user;
        });
    }
}
