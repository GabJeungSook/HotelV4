<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| The All notifications page (/notifications) and the shared bell feed. The desk, back office, admin
| and owner reach the page and get a bell feed; the housekeeper and kiosk get neither.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    Hotel::openShift();
});

it('opens the all-notifications page for the front desk', function (): void {
    $this->actingAs(Hotel::frontdesk())
        ->get(route('notifications'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Notifications/Index')->has('rows')->has('at'));
});

it('refuses the housekeeper and the kiosk', function (): void {
    $this->actingAs(Hotel::user('dodong.lumayag'))->get(route('notifications'))->assertForbidden();
    $this->actingAs(Hotel::user('kiosk-gensan-1'))->get(route('notifications'))->assertForbidden();
});

it('shares a bell feed for the front desk', function (): void {
    $this->actingAs(Hotel::frontdesk())
        ->get(route('notifications'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('notifications.count')
            ->has('notifications.items')
            ->where('notifications.href', route('notifications', absolute: false)));
});

it('shares no bell feed for the housekeeper', function (): void {
    $this->actingAs(Hotel::user('dodong.lumayag'))
        ->get(route('housekeeping.index'))
        ->assertInertia(fn (Assert $page) => $page->where('notifications', null));
});
