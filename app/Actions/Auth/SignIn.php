<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Exceptions\SignInRefused;
use App\Models\User;
use App\Support\Clock;
use Illuminate\Support\Facades\Hash;

/**
 * Check a username and password (screen 1, APP_FLOW §4.1 steps 4–6, TRD §9).
 *
 * Refuses an unknown, switched-off or locked account. A wrong password adds one to
 * users.failed_logins; the fifth sets locked_until = now + 15 minutes. A correct password sets
 * failed_logins back to 0. The counter is one atomic UPDATE, so it is kept although the
 * refusal is thrown. A PIN lock on the computer never blocks a password sign-in.
 */
final class SignIn
{
    public function __construct(private readonly Clock $clock) {}

    public function handle(string $username, string $password): User
    {
        $user = User::query()->where('username', trim($username))->first();
        $now = $this->clock->now();
        $max = $this->config('max_attempts', 5);
        $lockMinutes = $this->config('lock_minutes', 15);

        if ($user === null) {
            throw SignInRefused::wrong(null, $lockMinutes);
        }

        if ($user->locked_until !== null && $user->locked_until->greaterThan($now)) {
            throw SignInRefused::locked(max(1, (int) ceil(($user->locked_until->getTimestamp() - $now->getTimestamp()) / 60)));
        }

        if (! Hash::check($password, $user->password)) {
            $failed = $user->failed_logins + 1;
            $lock = $failed >= $max;

            User::query()->whereKey($user->id)->update([
                'failed_logins' => $lock ? 0 : $failed,
                'locked_until' => $lock ? $now->addMinutes($lockMinutes) : null,
            ]);

            throw $lock ? SignInRefused::locked($lockMinutes) : SignInRefused::wrong($max - $failed, $lockMinutes);
        }

        if (! $user->is_active) {
            throw SignInRefused::inactive();
        }

        User::query()->whereKey($user->id)->update(['failed_logins' => 0, 'locked_until' => null, 'last_seen_at' => $now]);

        return $user->refresh();
    }

    private function config(string $key, int $default): int
    {
        $value = config("hotel.login.{$key}", $default);

        return is_int($value) ? $value : $default;
    }
}
