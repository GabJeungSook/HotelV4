<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * The `last_seen_at` heartbeat of the housekeeping queue (APP_FLOW §4.17 step 1): each load
 * and each 20-second poll touches it, at most once a minute, so CleaningQuery can count the
 * housekeepers online. One UPDATE outside any transaction; not a business action.
 */
final class Presence
{
    public function __construct(private readonly Clock $clock) {}

    public function touch(User $user): void
    {
        $now = $this->clock->now();
        $every = (int) config('hotel.housekeeping.touch_seconds', 60);

        if ($user->last_seen_at !== null && $user->last_seen_at->greaterThan($now->subSeconds($every))) {
            return;
        }

        User::query()->whereKey($user->id)->update(['last_seen_at' => $now]);
        $user->forceFill(['last_seen_at' => $now])->syncOriginalAttribute('last_seen_at');
    }
}
