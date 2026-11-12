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
 * Set your 4-digit PIN at first sign-in (APP_FLOW §8.2, the PIN pad of screen 3 typed twice).
 * Writes users.pin_hash (bcrypt, never shown or logged, TRD §9) and an audit_logs row without
 * the PIN.
 */
final class SetPin
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    public function handle(User $user, string $pin, string $confirmation): User
    {
        $length = config('hotel.pin.length', 4);
        $length = is_int($length) ? $length : 4;

        if (! preg_match('/^\d{'.$length.'}$/', $pin)) {
            throw ActionRefused::because("A PIN is {$length} digits.");
        }

        if ($pin !== $confirmation) {
            throw ActionRefused::because('The two PINs are not the same. Type it again.');
        }

        if (preg_match('/^(\d)\1+$/', $pin) === 1) {
            throw ActionRefused::because('Pick a PIN that is not one digit repeated.');
        }

        return $this->transaction->run(function () use ($user, $pin): User {
            $user->forceFill(['pin_hash' => Hash::make($pin)])->save();

            AuditLog::query()->create([
                'branch_id' => $user->branch_id,
                'actor_id' => $user->id,
                'action' => 'user.pin_set',
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'before_json' => null,
                'after_json' => ['pin_set' => true],
                'created_at' => $this->clock->now(),
            ]);

            return $user;
        });
    }
}
