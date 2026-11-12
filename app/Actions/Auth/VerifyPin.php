<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\PinContext;
use App\Models\User;
use App\Services\PinGuard;
use Carbon\CarbonImmutable;

/**
 * Check a member's PIN on this computer: switch user (3) and the incoming front desk at
 * handover (25). Every try writes pin_attempts; ten wrong in a row lock the computer
 * (PinLocked). On switch user the third wrong PIN in a row sets PinInvalid::$mustSignIn,
 * and the caller ends the session (TRD §4.12). Writes nothing else.
 */
final class VerifyPin
{
    public function __construct(private readonly PinGuard $pins) {}

    /**
     * @param  CarbonImmutable|null  $since  the session's sign-in: switch-user tries before it are not counted
     */
    public function handle(User $user, string $pin, string $deviceKey, PinContext $context = PinContext::SwitchUser, ?CarbonImmutable $since = null): User
    {
        $limit = $context === PinContext::SwitchUser ? $this->switchUserLimit() : null;

        $this->pins->check($user, $pin, $deviceKey, $context, $user->branch_id, $limit, $since);

        return $user;
    }

    private function switchUserLimit(): int
    {
        $value = config('hotel.pin.switch_user_fallback_after', 3);

        return is_int($value) ? $value : 3;
    }
}
