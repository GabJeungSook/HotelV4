<?php

declare(strict_types=1);

namespace App\Http\Controllers\Owner;

use App\Actions\Owner\CreateOwnerAccount;
use App\Actions\Owner\UpdateOwnerAccount;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\OwnerAccountRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner accounts (APP_FLOW §8.1, §8.2): the table and form of screen 47 for role owner. Owner
 * only (route group). A new account or a password reset gets a temporary password, flashed once.
 */
final class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        $me = self::user($request);

        return Inertia::render('Owner/Accounts', [
            'accounts' => User::query()->where('role', Role::Owner)->orderByDesc('is_active')->orderBy('name')->get()
                ->map(fn (User $u): array => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'username' => $u->username,
                    'is_active' => $u->is_active,
                    'is_me' => $u->id === $me->id,
                    'last_seen_at' => $u->last_seen_at?->toIso8601String(),
                ])->values()->all(),
        ]);
    }

    public function store(OwnerAccountRequest $request, CreateOwnerAccount $create): RedirectResponse
    {
        $result = $create->handle(self::user($request), $request->string('name')->toString(), $request->string('username')->toString());

        Inertia::flash('created', [
            'kind' => 'owner',
            'name' => $result['user']->name,
            'username' => $result['user']->username,
            'password' => $result['password'],
        ]);

        return redirect()->route('owner.accounts');
    }

    public function update(OwnerAccountRequest $request, User $user, UpdateOwnerAccount $update): RedirectResponse
    {
        abort_unless($user->role === Role::Owner, 404);

        $result = $update->handle(self::user($request), $user->id, [
            'name' => $request->string('name')->toString(),
            'username' => $request->string('username')->toString(),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : $user->is_active,
            'reset_password' => $request->boolean('reset_password'),
        ]);

        if ($result['password'] !== null) {
            Inertia::flash('created', [
                'kind' => 'reset',
                'name' => $result['user']->name,
                'username' => $result['user']->username,
                'password' => $result['password'],
            ]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => "{$result['user']->name} is saved."]);
        }

        return redirect()->route('owner.accounts');
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
