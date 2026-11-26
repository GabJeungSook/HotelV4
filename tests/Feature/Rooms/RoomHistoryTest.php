<?php

declare(strict_types=1);

use App\Actions\Stays\CheckOutGuest;
use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Models\RoomStatusLog;
use App\Policies\RoomPolicy;
use App\Queries\RoomHistoryQuery;
use App\Support\Clock;
use Database\Seeders\DatabaseSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Hotel;

/*
| Room history (new screen; no mockup): the all-time timeline of one room — stays with their
| transactions and receipt, cleanings, and manual status changes. Read-only, admin + front desk.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    Hotel::openShift();
    $this->stay = Hotel::checkIn('101', 6);
    $this->room = Hotel::room('101');
});

it('builds the timeline with the stay, its transactions, a receipt, and cleaning', function (): void {
    $this->travelClock(120);
    app(CheckOutGuest::class)->handle(Hotel::frontdesk(), $this->stay->fresh());

    $timeline = app(RoomHistoryQuery::class)->timeline($this->room->fresh());
    $kinds = array_column($timeline['entries'], 'kind');

    expect($kinds)->toContain('stay')
        ->and($kinds)->toContain('cleaning')
        ->and($timeline['total'])->toBeGreaterThanOrEqual(2);

    $stayEntry = collect($timeline['entries'])->firstWhere('kind', 'stay');
    expect($stayEntry['code'])->toBe($this->stay->code)
        ->and($stayEntry['transactions']['lines'])->not->toBeEmpty()
        ->and($stayEntry['receipt'])->toHaveKeys(['header', 'lines', 'total', 'by']);
});

it('shows a manual status change and paginates newest first', function (): void {
    RoomStatusLog::query()->create([
        'room_id' => $this->room->id,
        'from_status' => RoomStatus::Available,
        'to_status' => RoomStatus::Maintenance,
        'reason' => RoomStatusReason::Admin,
        'user_id' => Hotel::frontdesk()->id,
        'created_at' => app(Clock::class)->now(),
    ]);

    $all = app(RoomHistoryQuery::class)->timeline($this->room->fresh());
    expect(array_column($all['entries'], 'kind'))->toContain('status');

    $first = app(RoomHistoryQuery::class)->timeline($this->room->fresh(), 1, 0);
    $second = app(RoomHistoryQuery::class)->timeline($this->room->fresh(), 1, 1);

    expect($first['entries'])->toHaveCount(1)
        ->and($second['entries'])->toHaveCount(1)
        ->and($first['entries'][0])->not->toEqual($second['entries'][0])
        ->and($first['total'])->toBe($all['total']);
});

it('filters by type and searches the whole history', function (): void {
    $this->travelClock(120);
    app(CheckOutGuest::class)->handle(Hotel::frontdesk(), $this->stay->fresh());
    $room = $this->room->fresh();
    $q = app(RoomHistoryQuery::class);

    // Type filter keeps only that kind.
    $cleaning = $q->timeline($room, 20, 0, 'cleaning');
    expect(collect($cleaning['entries'])->pluck('kind')->unique()->values()->all())->toBe(['cleaning']);

    // Search by guest name and by code finds the stay; a miss returns nothing.
    expect($q->timeline($room, 20, 0, null, 'Rico')['total'])->toBeGreaterThanOrEqual(1)
        ->and($q->timeline($room, 20, 0, null, $this->stay->code)['total'])->toBeGreaterThanOrEqual(1)
        ->and($q->timeline($room, 20, 0, null, 'zzz-no-such-thing')['total'])->toBe(0);
});

it('opens for the front desk and for admin of the branch', function (): void {
    $this->actingAs(Hotel::frontdesk())->get(route('rooms.history', $this->room->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Rooms/History')->where('room.number', '101'));

    $this->actingAs(Hotel::user('ramil.ortega'))->get(route('rooms.history', $this->room->id))->assertOk();
});

it('allows front desk, supervisor and admin, but not a kiosk', function (): void {
    $policy = new RoomPolicy;
    $room = $this->room->fresh();

    expect($policy->viewHistory(Hotel::frontdesk(), $room))->toBeTrue()
        ->and($policy->viewHistory(Hotel::supervisor(), $room))->toBeTrue()
        ->and($policy->viewHistory(Hotel::user('ramil.ortega'), $room))->toBeTrue()
        ->and($policy->viewHistory(Hotel::user('kiosk-gensan-1'), $room))->toBeFalse();
});
