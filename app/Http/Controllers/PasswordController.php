<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Auth\ChangePassword;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Change a temporary password at first sign-in (APP_FLOW §8.1 /password/change, §8.2). */
final class PasswordController extends Controller
{
    public function edit(Request $request): Response|RedirectResponse
    {
        if (! $this->user($request)->must_change_password) {
            return redirect()->route('home');
        }

        return Inertia::render('Auth/ChangePassword', ['min' => ChangePassword::MIN_LENGTH]);
    }

    public function update(Request $request, ChangePassword $change): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
            'password_confirmation' => ['required', 'string'],
        ], ['password.required' => 'Type a new password.', 'password_confirmation.required' => 'Type it again.']);

        $user = $this->user($request);

        if (! $user->must_change_password) {
            return redirect()->route('home');
        }

        $change->handle($user, $request->string('password')->toString(), $request->string('password_confirmation')->toString());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your password is changed.']);

        return redirect()->route('home');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
