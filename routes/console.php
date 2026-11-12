<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Kiosk holds and reservations (SYSTEM_DESIGN §5.4, APP_FLOW §4.18 step 10 and §4.19).
Schedule::command('homi:expire-kiosk-holds')->everyMinute()->withoutOverlapping();
Schedule::command('homi:release-no-shows')->everyFiveMinutes()->withoutOverlapping();
