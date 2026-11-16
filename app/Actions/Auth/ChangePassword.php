<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\ActionRefused;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Clock;
use App\Support\Transaction;
use Illuminate\Support\Facades\Hash;

/**
 * Replace a temporary password at first sign-in (APP_FLOW §8.1, §8.2; TRD §4.9, §9): at least
 * 8 characters, typed twice, not the temporary one. Clears users.must_change_password and
 * writes an audit_logs row without the password.
 */
final class ChangePassword
{
    public const MIN_LENGTH = 8;

    public function __construct(
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $user, string $password, string $confirmation): User
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            throw ActionRefused::because('A password has at least '.self::MIN_LENGTH.' characters.');
        }

        if ($password !== $confirmation) {
            throw ActionRefused::because('The two passwords are not the same. Type it again.');
        }

        if (Hash::check($password, $user->password)) {
            throw ActionRefused::because('Choose a new password, not the temporary one.');
        }

        return $this->transaction->run(function () use ($user, $password): User {
            $user->forceFill(['password' => $password, 'must_change_password' => false])->save();

            AuditLog::query()->create([
                'branch_id' => $user->branch_id,
                'actor_id' => $user->id,
                'action' => 'user.password_changed',
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'before_json' => ['must_change_password' => true],
                'after_json' => ['must_change_password' => false],
                'created_at' => $this->clock->now(),
            ]);

            return $user;
        });
    }
}
