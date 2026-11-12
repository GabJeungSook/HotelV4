<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HoldExpiry;
use Illuminate\Console\Command;

/**
 * Every minute (SYSTEM_DESIGN §5.4): held stays past hold_expires_at become expired. The room
 * never left available, so it is on sale again; no other row is written (APP_FLOW §6 case 8).
 */
final class ExpireKioskHolds extends Command
{
    protected $signature = 'homi:expire-kiosk-holds';

    protected $description = 'Expire kiosk holds that were not paid in time';

    public function handle(HoldExpiry $holds): int
    {
        $count = $holds->expireDue();
        $this->info("Expired {$count} kiosk hold(s).");

        return self::SUCCESS;
    }
}
