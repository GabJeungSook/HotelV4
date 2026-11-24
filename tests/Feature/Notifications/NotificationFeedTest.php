<?php

declare(strict_types=1);

use App\Models\Stay;
use App\Queries\NotificationQuery;
use App\Support\Clock;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\Hotel;

/*
| The notification feed (NotificationQuery): the branch's "Needs attention" rows plus, for the
| front desk, the live desk work it does not carry (due-out-soon, kiosk holds, unpaid balances).
| Role-aware, most urgent first, never a peso amount on the desk feed (CLAUDE.md rule 9).
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    Hotel::openShift();
    $this->now = app(Clock::class)->now();
});

it('builds the front-desk feed with the live desk items and hides back-office rows', function (): void {
    $overdue = Hotel::checkIn('205', 12);
    $overdue->forceFill(['expected_checkout_at' => $this->now->subHours(3)])->save();

    $dueSoon = Hotel::checkIn('206', 12);
    $dueSoon->forceFill(['expected_checkout_at' => $this->now->addMinutes(10)])->save();

    Stay::factory()->held()->create([
        'branch_id' => Hotel::branch()->id,
        'room_id' => Hotel::room('101')->id,
        'hold_expires_at' => $this->now->addMinutes(20),
    ]);

    $feed = app(NotificationQuery::class)->feed(Hotel::frontdesk(), [Hotel::branch()->id]);
    $kinds = array_column($feed, 'kind');

    expect($kinds)->toContain('overdue_stay')
        ->toContain('due_out_soon')
        ->toContain('kiosk_hold')
        ->not->toContain('no_pin')          // Liza has no PIN, but that is back-office work
        ->not->toContain('day_not_closed');
});

it('keeps every peso amount off the front-desk feed', function (): void {
    $overdue = Hotel::checkIn('205', 12);
    $overdue->forceFill(['expected_checkout_at' => $this->now->subHours(3)])->save();

    $feed = app(NotificationQuery::class)->feed(Hotel::frontdesk(), [Hotel::branch()->id]);

    foreach ($feed as $item) {
        expect($item['title'])->not->toContain('₱')
            ->and($item['detail'])->not->toContain('₱');
    }
});

it('links every desk notification to a front-desk screen, never a back-office one', function (): void {
    // A low-stock product so the stock notification appears.
    Hotel::product('Energy drink')->forceFill(['stock_on_hand' => 0])->save();

    $feed = app(NotificationQuery::class)->feed(Hotel::frontdesk(), [Hotel::branch()->id]);
    $stock = collect($feed)->firstWhere('kind', 'stock');

    expect($stock)->not->toBeNull()
        ->and($stock['href'])->toBe(route('stock.index', absolute: false));    // the desk Stock screen, not reports/inventory

    foreach ($feed as $item) {
        expect($item['href'])->not->toStartWith('/reports')                    // back office only
            ->and($item['href'])->not->toStartWith('/admin');                  // admin/owner only
    }
});

it('gives back office the attention feed but not the desk-only items', function (): void {
    $feed = app(NotificationQuery::class)->feed(Hotel::user('grace.tolentino'), [Hotel::branch()->id]);
    $kinds = array_column($feed, 'kind');

    expect($kinds)->toContain('no_pin')
        ->not->toContain('due_out_soon')
        ->not->toContain('kiosk_hold');
});

it('gives the housekeeper and the kiosk no feed at all', function (): void {
    expect(app(NotificationQuery::class)->feed(Hotel::user('dodong.lumayag'), [Hotel::branch()->id]))->toBe([])
        ->and(app(NotificationQuery::class)->feed(Hotel::user('kiosk-gensan-1'), [Hotel::branch()->id]))->toBe([]);
});

it('sorts the most urgent first', function (): void {
    $overdue = Hotel::checkIn('205', 12);
    $overdue->forceFill(['expected_checkout_at' => $this->now->subHours(3)])->save();
    $dueSoon = Hotel::checkIn('206', 12);
    $dueSoon->forceFill(['expected_checkout_at' => $this->now->addMinutes(10)])->save();

    $feed = app(NotificationQuery::class)->feed(Hotel::frontdesk(), [Hotel::branch()->id]);

    // The overdue room (high) comes before the due-soon room (normal).
    expect($feed[0]['severity'])->toBe('high')
        ->and($feed[0]['kind'])->toBe('overdue_stay');
});
