<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

/**
 * The branch of this request (TRD §3.6, SYSTEM_DESIGN §5).
 *
 * - A staff user's branch is their users.branch_id.
 * - An owner's branch is the one they opened, kept in the server session.
 *   An owner who has opened none works on "All branches" (null).
 * - Console commands and tests set the branch explicitly with set() or run().
 *
 * A branch id from the browser is never trusted; open() is called by the
 * owner's branch switch after a policy check.
 *
 * null means "no branch filter": the BelongsToBranch scope is not applied.
 * Only owners, guests of public pages and console code can be in that state.
 */
final class CurrentBranch
{
    public const SESSION_KEY = 'homi.owner_branch_id';

    private bool $isSet = false;

    private ?int $branchId = null;

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Request $request,
    ) {}

    public function id(): ?int
    {
        if ($this->isSet) {
            return $this->branchId;
        }

        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            return null;
        }

        if ($user->role !== Role::Owner) {
            return $user->branch_id;
        }

        if (! $this->request->hasSession()) {
            return null;
        }

        $opened = $this->request->session()->get(self::SESSION_KEY);

        return is_int($opened) ? $opened : null;
    }

    public function branch(): ?Branch
    {
        $id = $this->id();

        return $id === null ? null : Branch::query()->find($id);
    }

    /** Set the branch for console code and tests. null clears the filter. */
    public function set(?int $branchId): void
    {
        $this->isSet = true;
        $this->branchId = $branchId;
    }

    /** Go back to resolving the branch from the signed-in user. */
    public function reset(): void
    {
        $this->isSet = false;
        $this->branchId = null;
    }

    /**
     * Run a callback with a branch set, then restore what was there before.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(?int $branchId, callable $callback): mixed
    {
        [$wasSet, $was] = [$this->isSet, $this->branchId];
        $this->set($branchId);

        try {
            return $callback();
        } finally {
            [$this->isSet, $this->branchId] = [$wasSet, $was];
        }
    }

    /** The owner opens a branch (stored server-side in the session). */
    public function open(int $branchId): void
    {
        $this->request->session()->put(self::SESSION_KEY, $branchId);
    }

    /** The owner goes back to "All branches". */
    public function close(): void
    {
        if ($this->request->hasSession()) {
            $this->request->session()->forget(self::SESSION_KEY);
        }
    }
}
