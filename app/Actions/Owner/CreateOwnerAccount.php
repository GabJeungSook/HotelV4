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
 * Add an owner account (owner accounts, APP_FLOW §8.2; TRD §4.9 "First owner account"). Role
 * owner, no branch. From the owner menu the new owner gets a temporary password shown once;
 * `homi:create-owner` on the server passes the password typed at the prompt (`$by` is null
 * there). Writes audit_logs `owner.created`; the password is never logged.
 */
final class CreateOwnerAccount
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    /** @return array{user: User, password: string} */
    public function handle(?User $by, string $name, string $username, ?string $password = null): array
    {
        if ($by !== null && $by->role !== Role::Owner) {
            throw ActionRefused::because('Only an owner can add an owner account.');
        }

        // A password typed at the server (homi:create-owner) is the owner's own; one made up
        // here is temporary and must be changed at first sign-in (TRD §4.9).
        $temporary = $password === null;
        $password ??= CreateBranch::temporaryPassword();

        try {
            return $this->transaction->run(function () use ($by, $name, $username, $password, $temporary): array {
                $user = User::query()->create([
                    'branch_id' => null,
                    'name' => $name,
                    'username' => $username,
                    'password' => $password,
                    'must_change_password' => $temporary,
                    'pin_hash' => null,
                    'role' => Role::Owner,
                    'is_active' => true,
                ]);

                AuditLog::query()->create([
                    'branch_id' => null,
                    'actor_id' => $by?->id,
                    'action' => 'owner.created',
                    'subject_type' => 'user',
                    'subject_id' => $user->id,
                    'after_json' => ['name' => $user->name, 'username' => $user->username, 'role' => Role::Owner->value],
                    'created_at' => $this->clock->now(),
                ]);

                return ['user' => $user, 'password' => $password];
            });
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'users_username_unique')) {
                throw ActionRefused::because("The username {$username} is taken.");
            }

            throw $e;
        }
    }
}
