<?php

declare(strict_types=1);

use App\Support\Clock;
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

it('counts items as unread until the bell is marked seen, then keeps them open but off the badge', function (): void {
    // An overdue room is an open item, and unread because the bell has never been opened.
    $stay = Hotel::checkIn('205', 12);
    $stay->forceFill(['expected_checkout_at' => app(Clock::class)->now()->subHours(3)])->save();
    $frontdesk = Hotel::frontdesk();

    $this->actingAs($frontdesk)->get(route('today'))
        ->assertInertia(fn (Assert $page) => $page->where('notifications.unread', fn (int $u): bool => $u >= 1));

    // Opening the bell marks the feed seen.
    $this->actingAs($frontdesk)->post(route('notifications.seen'))->assertRedirect();
    expect($frontdesk->fresh()?->notifications_seen_at)->not->toBeNull();

    // The badge is now clear, but the item is still open (it still shows in the list/count).
    $this->actingAs($frontdesk)->get(route('today'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.unread', 0)
            ->where('notifications.count', fn (int $c): bool => $c >= 1));
});

it('refuses marking seen for the housekeeper and the kiosk', function (): void {
    $this->actingAs(Hotel::user('dodong.lumayag'))->post(route('notifications.seen'))->assertForbidden();
    $this->actingAs(Hotel::user('kiosk-gensan-1'))->post(route('notifications.seen'))->assertForbidden();
});
