<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\Role;
use App\Exceptions\UnlockRefused;
use App\Models\AuditLog;
use App\Models\PinLock;
use App\Models\User;
use App\Support\Clock;
use App\Support\Transaction;
use Illuminate\Support\Facades\Hash;

/**
 * A supervisor or admin unlocks PIN entry on a computer with their own username and password
 * (TRD §4.12, POST /pin/unlock). Writes pin_locks.unlocked_at and unlocked_by and an
 * audit_logs row; no approvals row.
 */
final class UnlockPin
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    public function handle(string $deviceKey, string $username, string $password): PinLock
    {
        return $this->transaction->run(function () use ($deviceKey, $username, $password): PinLock {
            $lock = PinLock::query()->where('open_device_key', $deviceKey)->lockForUpdate()->first()
                ?? throw UnlockRefused::notLocked();

            $user = User::query()->where('username', $username)->first();

            if ($user === null || ! $user->is_active || ! in_array($user->role, [Role::Supervisor, Role::Admin], true)
                || ! Hash::check($password, $user->password)) {
                throw UnlockRefused::make();
            }

            $now = $this->clock->now();
            $lock->update(['unlocked_at' => $now, 'unlocked_by' => $user->id]);

            AuditLog::query()->create([
                'branch_id' => $user->branch_id,
                'actor_id' => $user->id,
                'action' => 'pin.unlocked',
                'subject_type' => 'pin_lock',
                'subject_id' => $lock->id,
                'before_json' => ['device_key' => $deviceKey, 'locked_at' => $lock->locked_at->toIso8601String()],
                'after_json' => ['unlocked_at' => $now->toIso8601String(), 'unlocked_by' => $user->id],
                'created_at' => $now,
            ]);

            return $lock;
        });
    }
}
