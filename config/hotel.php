<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Homi product-wide constants (TRD §3.9)
|--------------------------------------------------------------------------
|
| Values that are the same for every branch. Per-branch values live on the
| `branches` table and are read through the Branch model. Nothing here is
| about one particular hotel.
|
*/

return [

    // Product name in page titles, the browser tab and fallback text (ASSETS).
    'name' => 'Homi',

    // Stored in UTC, shown in this timezone (TRD §1).
    'display_timezone' => 'Asia/Manila',

    'currency' => [
        'symbol' => '₱',
        'code' => 'PHP',
    ],

    // The bills of the blind count at close (screen 23), in centavos, largest first. Coins
    // are typed as one total (cash_counts.denominations "coins").
    'cash_count' => [
        'bills' => [100000, 50000, 20000, 10000, 5000, 2000],
    ],

    'pin' => [
        // Digits in a staff PIN.
        'length' => 4,
        // Wrong PINs in a row on one computer before PIN entry locks (TRD §4.12).
        'computer_lock_after' => 10,
        // Wrong PINs on switch user before falling back to the full sign-in (TRD §4.12).
        'switch_user_fallback_after' => 3,
        // Wrong PINs before one approver is locked, and for how long (SYSTEM_DESIGN §6, ApproveByPin).
        'approver_lock_after' => 5,
        'approver_lock_minutes' => 15,
    ],

    // Username and password sign-in lock (TRD §3.9).
    'login' => [
        'max_attempts' => 5,
        'lock_minutes' => 15,
    ],

    // Polling intervals in seconds (TRD §3.9, §4.11).
    'polling' => [
        // The room-board countdown now ticks live in the browser; this is only the background
        // reload that keeps the board's data (new check-ins, housekeeping) fresh.
        'board' => 30,
        'kiosk_holds' => 5,
        'housekeeping' => 20,
        'kiosk_availability' => 20,
        'heartbeat' => 5,
        'dashboard' => 30,
    ],

    // "Needs attention" thresholds in minutes (TRD §4.9, screen 49).
    'attention' => [
        'stay_overdue_minutes' => 60,
        'shift_open_past_end_minutes' => 120,
        'cleaning_in_progress_minutes' => 60,
    ],

    // Housekeeping queue (32, TRD §4.7). A housekeeper counts as online when last_seen_at is
    // within online_minutes; the queue touches last_seen_at at most once a touch_seconds. A
    // waiting room's label turns amber from waiting_warn_minutes (red "Urgent" comes from
    // branches.cleaning_urgent_minutes).
    'housekeeping' => [
        'online_minutes' => 2,
        'touch_seconds' => 60,
        'waiting_warn_minutes' => 30,
        'checklist' => ['Fresh sheets and towels', 'Bathroom', 'Trash', 'Remote and key on the table'],
    ],

    // Welcome page (59) headline and sub-line (TRD §4.11), text from the mockup.
    'welcome' => [
        'headline' => 'Rest well, by the hour or the night.',
        'subline' => 'Clean, quiet rooms close to the city centre. Choose your room at the lobby kiosk or at the front desk.',
    ],

    // Photo credits for the welcome page footer, keyed by file name (ASSETS §1.5,
    // public/images/photos/CREDITS.md). Remove an entry when its photo is replaced.
    'photo_credits' => [
        'signin-morning.jpg' => ['by' => 'Hiram Huang', 'url' => 'https://unsplash.com/photos/NIbUk7ATSuw'],
        'signin-afternoon.jpg' => ['by' => 'Marcel Strauß', 'url' => 'https://unsplash.com/photos/CMHgRFDANH8'],
        'signin-evening.jpg' => ['by' => 'Vojtech Bruzek', 'url' => 'https://unsplash.com/photos/Yrxr3bsPdS0'],
        'room-deluxe.jpg' => ['by' => 'Vojtech Bruzek', 'url' => 'https://unsplash.com/photos/Yrxr3bsPdS0'],
        'lobby.jpg' => ['by' => 'Frames For Your Heart', 'url' => 'https://unsplash.com/photos/zSG-kd-L6vw'],
        'room-standard.jpg' => ['by' => 'Point3D Commercial Imaging Ltd.', 'url' => 'https://unsplash.com/photos/oxeCZrodz78'],
        'room-suite.jpg' => ['by' => 'visualsofdana', 'url' => 'https://unsplash.com/photos/T5pL6ciEn-I'],
    ],

];
