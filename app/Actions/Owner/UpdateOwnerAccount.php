<?php

declare(strict_types=1);

namespace App\Actions\Owner;

use App\Enums\Role;
use App\Exceptions\ActionRefused;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Clock;
use App\Support\DuplicateKey;
use App\Support\Transaction;
use Illuminate\Database\QueryException;

/**
 * Edit an owner account (owner accounts, APP_FLOW §8.2): name, username, active, and an optional
 * password reset that returns a new temporary password shown once. An owner cannot deactivate
 * their own account, so at least one owner can always sign in. Writes audit_logs
 * (`owner.updated`, `owner.deactivated`, `owner.reactivated`, `owner.password_reset`) with the
 * before and after values; passwords are never logged.
 */
final class UpdateOwnerAccount
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    /**
     * @param  array{name: string, username: string, is_active: bool, reset_password?: bool}  $data
     * @return array{user: User, password: string|null}
     */
    public function handle(User $by, int $userId, array $data): array
    {
        if ($by->role !== Role::Owner) {
            throw ActionRefused::because('Only an owner can change owner accounts.');
        }

        if ($by->id === $userId && ! $data['is_active']) {
            throw ActionRefused::because('You cannot deactivate your own account.');
        }

        try {
            return $this->transaction->run(function () use ($by, $userId, $data): array {
                $user = User::query()->where('role', Role::Owner)->lockForUpdate()->findOrFail($userId);
                $before = ['name' => $user->name, 'username' => $user->username, 'is_active' => $user->is_active];
                $after = ['name' => $data['name'], 'username' => $data['username'], 'is_active' => $data['is_active']];
                $password = ($data['reset_password'] ?? false) ? CreateBranch::temporaryPassword() : null;

                $user->fill($after);
                if ($password !== null) {
                    $user->password = $password;
                    $user->must_change_password = true;
                }
                $user->save();

                $log = fn (string $action, ?array $old, ?array $new) => AuditLog::query()->create([
                    'branch_id' => null,
                    'actor_id' => $by->id,
                    'action' => $action,
                    'subject_type' => 'user',
                    'subject_id' => $user->id,
                    'before_json' => $old,
                    'after_json' => $new,
                    'created_at' => $this->clock->now(),
                ]);

                $changed = array_diff_assoc(
                    ['name' => $after['name'], 'username' => $after['username']],
                    ['name' => $before['name'], 'username' => $before['username']],
                );
                if ($changed !== []) {
                    $log('owner.updated', array_intersect_key($before, $changed), $changed);
                }

                if ($before['is_active'] !== $after['is_active']) {
                    $log($after['is_active'] ? 'owner.reactivated' : 'owner.deactivated', ['is_active' => $before['is_active']], ['is_active' => $after['is_active']]);
                }

                if ($password !== null) {
                    $log('owner.password_reset', null, null);
                }

                return ['user' => $user, 'password' => $password];
            });
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'users_username_unique')) {
                throw ActionRefused::because("The username {$data['username']} is taken.");
            }

            throw $e;
        }
    }
}
