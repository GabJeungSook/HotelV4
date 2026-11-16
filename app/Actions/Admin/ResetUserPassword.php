<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use App\Services\AuditTrail;
use App\Support\Transaction;

/**
 * Reset a user's password (47, APP_FLOW §8.2): a new temporary password, returned once for the
 * screen to show and never stored in plain text or logged; it must be changed at the next sign-in
 * (must_change_password). The sign-in lock is cleared too. One
 * transaction with its audit_logs row 'user.password_reset'.
 */
final class ResetUserPassword
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {}

    /** @return string the temporary password, to show once */
    public function handle(User $by, int $branchId, int $userId): string
    {
        return $this->transaction->run(function () use ($by, $branchId, $userId): string {
            $user = SaveUser::branchUser($branchId, $userId, lock: true);
            $password = SaveUser::temporaryPassword();

            $user->forceFill(['password' => $password, 'must_change_password' => true, 'failed_logins' => 0, 'locked_until' => null])->save();
            $this->audit->record($by, $branchId, 'user.password_reset', 'user', $user->id, null, ['temporary' => true]);

            return $password;
        });
    }
}
