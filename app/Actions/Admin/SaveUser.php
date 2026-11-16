<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Role;
use App\Exceptions\ActionRefused;
use App\Models\Floor;
use App\Models\User;
use App\Services\AuditTrail;
use App\Support\DuplicateKey;
use App\Support\Transaction;
use Illuminate\Database\QueryException;

/**
 * Add or edit a user of this branch (47; DATABASE_SCHEMA §4.2, §4.6). One transaction:
 *
 * - the role is one of the branch roles (admin, supervisor, front desk, back office,
 *   housekeeper, kiosk); owner accounts sit above the branches and are never made here;
 * - the username is unique across the installation;
 * - a housekeeper's floors (floor_user) are the branch's own floors; any other role has none;
 * - you cannot change your own role;
 * - a new user gets a temporary password (must_change_password: changed at first sign-in), returned once and never stored in
 *   plain text or in the audit log. The PIN is set by the user at first sign-in.
 *
 * Writes audit_logs 'user.created' or 'user.updated'. Deactivating is SetUserActive.
 */
final class SaveUser
{
    public const ROLES = [Role::Admin, Role::Supervisor, Role::Frontdesk, Role::Backoffice, Role::Housekeeper, Role::Kiosk];

    public function __construct(
        private readonly AuditTrail $audit,
        private readonly Transaction $transaction,
    ) {}

    /**
     * @param  list<int>  $floorIds
     * @return array{user: User, password: string|null} the temporary password of a new user, else null
     */
    public function handle(User $by, int $branchId, ?int $userId, string $name, string $username, Role $role, array $floorIds = []): array
    {
        if (! in_array($role, self::ROLES, true)) {
            throw ActionRefused::because('Owner accounts are managed from the owner menu, not from a branch.');
        }

        $username = mb_strtolower(trim($username));

        try {
            return $this->transaction->run(fn (): array => $this->save($by, $branchId, $userId, trim($name), $username, $role, $floorIds));
        } catch (QueryException $e) {
            if (DuplicateKey::on($e, 'users_username_unique')) {
                throw self::usernameTaken($username);
            }

            throw $e;
        }
    }

    /**
     * @param  list<int>  $floorIds
     * @return array{user: User, password: string|null}
     */
    private function save(User $by, int $branchId, ?int $userId, string $name, string $username, Role $role, array $floorIds): array
    {
        $user = $userId === null ? null : self::branchUser($branchId, $userId, lock: true);

        if (User::query()->where('username', $username)->when($user !== null, fn ($q) => $q->whereKeyNot($user?->id))->exists()) {
            throw self::usernameTaken($username);
        }

        if ($user !== null && $user->id === $by->id && $role !== $user->role) {
            throw ActionRefused::because('You cannot change your own role. Ask another admin or the owner.');
        }

        $floorIds = $role === Role::Housekeeper ? array_values(array_unique(array_map('intval', $floorIds))) : [];
        sort($floorIds);

        if (Floor::query()->withoutGlobalScopes()->where('branch_id', $branchId)->whereIn('id', $floorIds)->count() !== count($floorIds)) {
            throw ActionRefused::because('Pick floors of this branch.');
        }

        $after = ['name' => $name, 'username' => $username, 'role' => $role->value, 'floor_ids' => $floorIds];

        if ($user === null) {
            $password = self::temporaryPassword();
            $user = User::query()->create([
                'branch_id' => $branchId, 'name' => $name, 'username' => $username, 'role' => $role,
                'password' => $password, 'must_change_password' => true, 'pin_hash' => null, 'is_active' => true,
            ]);
            $user->floors()->sync($floorIds);
            $this->audit->record($by, $branchId, 'user.created', 'user', $user->id, null, $after);

            return ['user' => $user, 'password' => $password];
        }

        $before = [
            'name' => $user->name,
            'username' => $user->username,
            'role' => $user->role->value,
            'floor_ids' => $user->floors()->pluck('floors.id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
        ];
        [$old, $new] = AuditTrail::diff($before, $after);

        $user->update(['name' => $name, 'username' => $username, 'role' => $role]);
        $user->floors()->sync($floorIds);

        if ($new !== []) {
            $this->audit->record($by, $branchId, 'user.updated', 'user', $user->id, $old, $new);
        }

        return ['user' => $user, 'password' => null];
    }

    /** A user of this branch, never an owner; another branch's user is not found (404). */
    public static function branchUser(int $branchId, int $userId, bool $lock = false): User
    {
        return User::query()->where('branch_id', $branchId)->where('role', '!=', Role::Owner)
            ->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($userId);
    }

    /** Ten letters and digits, easy to read out (no 0/O, 1/l/I). */
    public static function temporaryPassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';

        for ($i = 0; $i < 10; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    private static function usernameTaken(string $username): ActionRefused
    {
        return ActionRefused::because("The username {$username} is already used. Pick another.");
    }
}
