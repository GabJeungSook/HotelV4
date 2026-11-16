<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use App\Services\AuditTrail;
use App\Support\Transaction;

/**
 * Reset a user's PIN (47, APP_FLOW §8.2): pin_hash becomes NULL and the user sets a new PIN at
 * their next sign-in. The admin never sees a PIN. One transaction with its audit_logs row
 * 'user.pin_reset'.
 */
final class ResetUserPin
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $by, int $branchId, int $userId): User
    {
        return $this->transaction->run(function () use ($by, $branchId, $userId): User {
            $user = SaveUser::branchUser($branchId, $userId, lock: true);
            $had = $user->pin_hash !== null;

            $user->forceFill(['pin_hash' => null])->save();
            $this->audit->record($by, $branchId, 'user.pin_reset', 'user', $user->id, ['pin_set' => $had], ['pin_set' => false]);

            return $user;
        });
    }
}
