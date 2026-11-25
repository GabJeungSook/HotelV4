<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Auth\SignIn;
use App\Enums\Role;
use App\Exceptions\SignInRefused;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Token sign-in for the native apps (Sanctum). Only the two device roles may hold a token: a
 * kiosk tablet and a roomboy's phone. Username + password go through the same SignIn action as
 * the web (lockouts and all); the token's ability is the role, and its branch is the user's.
 */
final class AuthController extends Controller
{
    /** @var list<Role> */
    private const APP_ROLES = [Role::Kiosk, Role::Housekeeper];

    public function login(Request $request, SignIn $signIn): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $user = $signIn->handle($data['username'], $data['password']);
        } catch (SignInRefused $e) {
            throw ValidationException::withMessages(['username' => $e->getMessage()]);
        }

        if (! in_array($user->role, self::APP_ROLES, true)) {
            throw ValidationException::withMessages(['username' => 'This account cannot use the mobile app.']);
        }

        $token = $user->createToken($data['device_name'], [$user->role->value])->plainTextToken;

        return response()->json(['token' => $token, 'user' => $this->payload($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->payload($this->user($request))]);
    }

    /** Revoke only the token this request came in on, so other devices stay signed in. */
    public function logout(Request $request): JsonResponse
    {
        $this->user($request)->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /** @return array{id: int, name: string, role: string, branch_id: int|null, branch_name: string|null} */
    private function payload(User $user): array
    {
        $branchName = $user->branch_id === null
            ? null
            : Branch::query()->withoutGlobalScopes()->whereKey($user->branch_id)->value('name');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role->value,
            'branch_id' => $user->branch_id,
            'branch_name' => is_string($branchName) ? $branchName : null,
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
