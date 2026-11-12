<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StayState;
use App\Models\Branch;
use App\Models\Stay;
use App\Support\Clock;
use Illuminate\Console\Command;

/**
 * Every 5 minutes (SYSTEM_DESIGN §5.4, APP_FLOW §4.19 "No-show"): a reserved stay whose
 * arrival_at + the branch's reservation_release_minutes has passed becomes no_show and its room
 * is released for sale. Money paid ahead stays as guest deposit on the stay until a supervisor
 * refunds or keeps it (RefundNoShow); nothing is written to the ledger here.
 */
final class ReleaseNoShows extends Command
{
    protected $signature = 'homi:release-no-shows';

    protected $description = 'Mark reservations whose guest did not arrive in time as no-show';

    public function handle(Clock $clock): int
    {
        $now = $clock->now();
        $count = 0;

        foreach (Branch::query()->withoutGlobalScopes()->get(['id', 'reservation_release_minutes']) as $branch) {
            $count += Stay::query()->withoutGlobalScopes()
                ->where('branch_id', $branch->id)
                ->where('state', StayState::Reserved)
                ->where('arrival_at', '<=', $now->subMinutes($branch->reservation_release_minutes))
                ->update([
                    'state' => StayState::NoShow,
                    'closed_reason' => "Not arrived {$branch->reservation_release_minutes} minutes after the arrival time",
                    'updated_at' => $now,
                ]);
        }

        $this->info("Released {$count} reservation(s) as no-show.");

        return self::SUCCESS;
    }
}
