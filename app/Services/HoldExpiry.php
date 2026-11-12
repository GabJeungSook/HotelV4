<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StayState;
use App\Models\Stay;
use App\Support\Clock;

/**
 * Kiosk holds that ran out of time (APP_FLOW §4.18 step 10, §6 cases 7 and 8). A held stay past
 * hold_expires_at becomes expired; nothing else is written, because a hold has no ledger row
 * and never moved the room out of available. The generated active_room_id drops to NULL with
 * the state, so the room is on sale again at once.
 *
 * Used every minute by ExpireKioskHolds, and inside the actions that need the room right now
 * (a walk-in, a new hold, a confirm), so a hold that expired a few seconds before the next run
 * never blocks a sale.
 */
final class HoldExpiry
{
    public const CLOSED_REASON = 'Hold expired, released automatically';

    public function __construct(private readonly Clock $clock) {}

    /** Expire every due hold, of one branch or one room when given. Returns how many. */
    public function expireDue(?int $branchId = null, ?int $roomId = null): int
    {
        return Stay::query()->withoutGlobalScopes()
            ->where('state', StayState::Held)
            ->where('hold_expires_at', '<=', $this->clock->now())
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($roomId !== null, fn ($q) => $q->where('room_id', $roomId))
            ->update(['state' => StayState::Expired, 'closed_reason' => self::CLOSED_REASON, 'updated_at' => $this->clock->now()]);
    }
}
