<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Kiosk holds and reservations (SYSTEM_DESIGN §5.4, APP_FLOW §4.18 step 10 and §4.19).
Schedule::command('homi:expire-kiosk-holds')->everyMinute()->withoutOverlapping();
Schedule::command('homi:release-no-shows')->everyFiveMinutes()->withoutOverlapping();

// The nightly check of the previous business day and of the data invariants (APP_FLOW §4.21).
Schedule::command('homi:reconcile-business-day')->dailyAt('09:00')->timezone(config('hotel.display_timezone'))->withoutOverlapping();
Schedule::command('homi:check-invariants')->dailyAt('09:05')->timezone(config('hotel.display_timezone'))->withoutOverlapping();
