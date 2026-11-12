<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PinContext;
use App\Exceptions\PinInvalid;
use App\Exceptions\PinLocked;
use App\Models\PinAttempt;
use App\Models\PinLock;
use App\Models\User;
use App\Support\Clock;
use App\Support\DuplicateKey;
use App\Support\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;

/**
 * PIN checks and the PIN lockouts of TRD §4.12 and §9, shared by VerifyPin and ApproveByPin.
 *
 * - Every try, right or wrong, writes one pin_attempts row for the computer (device_key).
 *   The row is written after the action's transaction ends, so a wrong PIN that rolls the
 *   action back is still counted (Transaction::afterwards).
 * - Ten wrong tries in a row on one computer, in any context, write a pin_locks row; while
 *   it is open every PIN on that computer is refused with PinLocked. A correct PIN, or a
 *   lock, starts a new run.
 * - Five wrong approval PINs by one approver within 15 minutes lock that approver's approvals.
 */
final class PinGuard
{
    public function __construct(
        private readonly Clock $clock,
        private readonly Transaction $transaction,
    ) {}

    public function isLocked(string $deviceKey): bool
    {
        return PinLock::query()->where('open_device_key', $deviceKey)->exists();
    }

    public function assertUnlocked(string $deviceKey): void
    {
        if ($this->isLocked($deviceKey)) {
            throw PinLocked::computer();
        }
    }

    /**
     * Check a user's PIN on a computer and log the try. Returns normally on a correct PIN;
     * throws PinInvalid (wrong), PinLocked (computer or approver locked).
     *
     * @param  int|null  $triesLimit  wrong tries allowed in this context before the caller's own fallback
     *                                (3 on switch user, 5 per approver); null for none
     * @param  CarbonImmutable|null  $since  only count wrong tries after this moment (the session's sign-in)
     */
    public function check(User $user, string $pin, string $deviceKey, PinContext $context, ?int $branchId, ?int $triesLimit = null, ?CarbonImmutable $since = null): void
    {
        $this->assertUnlocked($deviceKey);

        if ($user->pin_hash === null) {
            throw PinInvalid::noPinSet($user->name);
        }

        $ok = Hash::check($pin, $user->pin_hash);
        $wrongBefore = $ok || $triesLimit === null ? 0 : $this->wrongTriesFor($user, $deviceKey, $context, $since);
        $now = $this->clock->now();

        $this->transaction->afterwards(function () use ($deviceKey, $user, $context, $ok, $branchId, $now): void {
            PinAttempt::query()->create([
                'branch_id' => $branchId,
                'device_key' => $deviceKey,
                'user_id' => $user->id,
                'context' => $context,
                'succeeded' => $ok,
                'created_at' => $now,
            ]);

            if (! $ok) {
                $this->lockIfNeeded($deviceKey, $now);
            }
        });

        if (! $ok) {
            $left = $triesLimit === null ? null : max(0, $triesLimit - $wrongBefore - 1);

            throw PinInvalid::make($left, $context === PinContext::SwitchUser && $left === 0);
        }
    }

    /** Wrong tries in a row on a computer since its last correct PIN and its last lock. */
    public function wrongInARow(string $deviceKey): int
    {
        $lastSuccess = PinAttempt::query()->where('device_key', $deviceKey)->where('succeeded', true)->max('id');
        $lastLock = PinLock::query()->where('device_key', $deviceKey)->max('locked_at');

        return PinAttempt::query()
            ->where('device_key', $deviceKey)
            ->where('succeeded', false)
            ->when($lastSuccess !== null, fn ($q) => $q->where('id', '>', $lastSuccess))
            ->when($lastLock !== null, fn ($q) => $q->where('created_at', '>', $lastLock))
            ->count();
    }

    /** Whether an approver gave too many wrong approval PINs in the last minutes (TRD §9). */
    public function approverLocked(User $approver): bool
    {
        return $this->wrongApprovals($approver) >= $this->approverLimit();
    }

    public function approverLimit(): int
    {
        return $this->config('approver_lock_after', 5);
    }

    public function approverLockMinutes(): int
    {
        return $this->config('approver_lock_minutes', 15);
    }

    private function wrongTriesFor(User $user, string $deviceKey, PinContext $context, ?CarbonImmutable $since): int
    {
        if ($context === PinContext::Approval) {
            return $this->wrongApprovals($user);
        }

        $lastSuccess = PinAttempt::query()->where('device_key', $deviceKey)->where('succeeded', true)->max('id');

        return PinAttempt::query()
            ->where('device_key', $deviceKey)
            ->where('context', $context)
            ->where('succeeded', false)
            ->when($lastSuccess !== null, fn ($q) => $q->where('id', '>', $lastSuccess))
            ->when($since !== null, fn ($q) => $q->where('created_at', '>=', $since))
            ->count();
    }

    private function wrongApprovals(User $approver): int
    {
        $window = $this->clock->now()->subMinutes($this->approverLockMinutes());
        $lastSuccess = PinAttempt::query()->where('user_id', $approver->id)->where('context', PinContext::Approval)->where('succeeded', true)->max('id');

        return PinAttempt::query()
            ->where('user_id', $approver->id)
            ->where('context', PinContext::Approval)
            ->where('succeeded', false)
            ->where('created_at', '>', $window)
            ->when($lastSuccess !== null, fn ($q) => $q->where('id', '>', $lastSuccess))
            ->count();
    }

    private function lockIfNeeded(string $deviceKey, CarbonImmutable $now): void
    {
        if ($this->isLocked($deviceKey) || $this->wrongInARow($deviceKey) < $this->config('computer_lock_after', 10)) {
            return;
        }

        try {
            PinLock::query()->create(['device_key' => $deviceKey, 'locked_at' => $now]);
        } catch (QueryException $e) {
            // Another request locked it at the same moment: one open lock per computer.
            if (! DuplicateKey::on($e, 'pin_locks_one_open')) {
                throw $e;
            }
        }
    }

    private function config(string $key, int $default): int
    {
        $value = config("hotel.pin.{$key}", $default);

        return is_int($value) ? $value : $default;
    }
}
