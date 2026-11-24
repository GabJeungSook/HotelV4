<?php

declare(strict_types=1);

use App\Queries\KioskOrderQuery;
use Database\Seeders\DatabaseSeeder;
use Tests\Support\Hotel;

/*
| KioskOrderQuery (board add-on): per room type, the rooms the kiosk gives next in rotation order,
| and the busy rooms with when they free up. Read-only; it never changes the rotation.
*/

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->freezeClock('2026-11-12 03:00:00');
    $this->branch = Hotel::branch();
    $this->branch->update(['kiosk_enabled' => true]);
});

it('lists kiosk rooms in rotation order and occupied rooms as coming up', function (): void {
    Hotel::openShift();
    Hotel::checkIn('205', 12); // a Deluxe room, now occupied

    $out = app(KioskOrderQuery::class)->forBranch($this->branch->id);
    $deluxe = collect($out['types'])->firstWhere('name', 'Deluxe');
    $queue = collect($deluxe['queue'])->pluck('number');

    expect($out['enabled'])->toBeTrue()
        ->and($deluxe['queue_count'])->toBe($queue->count())
        ->and($queue)->not->toContain('205')          // occupied → out of the queue
        ->and($queue->all())->toBe($queue->sort(SORT_NATURAL)->values()->all()); // fresh rooms, so in room-number order

    $busy = collect($deluxe['busy'])->firstWhere('number', '205');
    expect($busy['reason'])->toBe('Occupied')
        ->and($busy['free_at'])->not->toBeNull();
});

it('drops a room from the queue once it is switched off the kiosk', function (): void {
    $room = Hotel::room('206'); // Deluxe
    $room->update(['is_kiosk_enabled' => false]);

    $out = app(KioskOrderQuery::class)->forBranch($this->branch->id);
    $deluxe = collect($out['types'])->firstWhere('name', 'Deluxe');

    expect(collect($deluxe['queue'])->pluck('number'))->not->toContain('206')
        ->and(collect($deluxe['busy'])->firstWhere('number', '206')['reason'])->toBe('Off kiosk');
});

it('reports the module off when the kiosk is disabled', function (): void {
    $this->branch->update(['kiosk_enabled' => false]);

    expect(app(KioskOrderQuery::class)->forBranch($this->branch->id)['enabled'])->toBeFalse();
});
