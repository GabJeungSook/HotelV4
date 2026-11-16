<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ResetUserPassword;
use App\Actions\Admin\ResetUserPin;
use App\Actions\Admin\SaveUser;
use App\Actions\Admin\SetUserActive;
use App\Enums\Role;
use App\Http\Requests\Admin\UserRequest;
use App\Queries\AdminSetupQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Users (47): this branch's accounts, floors for housekeepers, reset PIN, reset password,
 * deactivate and reactivate (APP_FLOW §8.2). A temporary password is flashed once as `secret`
 * (shown in a dialog after saving) and never sent again.
 */
final class UserController extends AdminController
{
    public function index(AdminSetupQuery $query): Response
    {
        return Inertia::render('Admin/Users', $query->users($this->branch()));
    }

    public function store(UserRequest $request, SaveUser $save): RedirectResponse
    {
        ['user' => $user, 'password' => $password] = $save->handle(
            $this->user($request), $this->branchId(), null,
            $request->string('name')->toString(), $request->string('username')->toString(),
            Role::from($request->string('role')->toString()), $this->floorIds($request),
        );

        Inertia::flash('secret', ['title' => "{$user->name} added", 'username' => $user->username, 'password' => $password]);

        return redirect()->route('admin.users');
    }

    public function update(UserRequest $request, SaveUser $save, int $user): RedirectResponse
    {
        ['user' => $saved] = $save->handle(
            $this->user($request), $this->branchId(), $user,
            $request->string('name')->toString(), $request->string('username')->toString(),
            Role::from($request->string('role')->toString()), $this->floorIds($request),
        );

        return $this->saved("{$saved->name} saved.");
    }

    public function resetPin(Request $request, ResetUserPin $reset, int $user): RedirectResponse
    {
        $saved = $reset->handle($this->user($request), $this->branchId(), $user);

        return $this->saved("PIN reset. {$saved->name} sets a new one at the next sign-in.");
    }

    public function resetPassword(Request $request, ResetUserPassword $reset, int $user): RedirectResponse
    {
        $password = $reset->handle($this->user($request), $this->branchId(), $user);
        $saved = SaveUser::branchUser($this->branchId(), $user);

        Inertia::flash('secret', ['title' => "New password for {$saved->name}", 'username' => $saved->username, 'password' => $password]);

        return redirect()->route('admin.users');
    }

    public function active(Request $request, SetUserActive $set, int $user): RedirectResponse
    {
        $active = $request->boolean('active');
        $saved = $set->handle($this->user($request), $this->branchId(), $user, $active);

        return $this->saved($active ? "{$saved->name} reactivated." : "{$saved->name} deactivated.");
    }

    /** @return list<int> */
    private function floorIds(Request $request): array
    {
        return array_values(array_map('intval', (array) $request->input('floor_ids', [])));
    }
}
