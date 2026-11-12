<?php

declare(strict_types=1);

use App\Enums\RoomStatus;
use App\Services\RoomRotation;
use App\Services\Rotation\RotationCandidate;
use Carbon\CarbonImmutable;

/*
| TRD §4.8: never-used rooms first, then the oldest last check-out, then the natural
| order of the room number; only free, kiosk-enabled rooms.
*/

function candidate(string $number, ?string $lastCheckout = null, RoomStatus $status = RoomStatus::Available, bool $kiosk = true, bool $active = false, bool $reserved = false): RotationCandidate
{
    static $id = 0;

    return new RotationCandidate(++$id, $number, $status, $kiosk, $active, $reserved, $lastCheckout === null ? null : CarbonImmutable::parse($lastCheckout));
}

function numbers(array $rooms): array
{
    return array_map(fn (RotationCandidate $r): string => $r->number, $rooms);
}

it('puts never-used rooms first, in natural number order', function (): void {
    $order = (new RoomRotation)->order([
        candidate('210', '2026-11-12 01:00'),
        candidate('3A'),
        candidate('10'),
        candidate('9'),
    ]);

    expect(numbers($order))->toBe(['3A', '9', '10', '210']);
});

it('then orders by the oldest last check-out', function (): void {
    $order = (new RoomRotation)->order([
        candidate('201', '2026-11-12 03:00'),
        candidate('202', '2026-11-11 23:00'),
        candidate('203', '2026-11-12 01:00'),
    ]);

    expect(numbers($order))->toBe(['202', '203', '201']);
});

it('breaks a tie on the check-out time by natural room number', function (): void {
    $order = (new RoomRotation)->order([
        candidate('212', '2026-11-12 01:00'),
        candidate('202', '2026-11-12 01:00'),
    ]);

    expect(numbers($order))->toBe(['202', '212']);
});

it('leaves out rooms that are not free for the kiosk', function (): void {
    $rotation = new RoomRotation;
    $order = $rotation->order([
        candidate('201', status: RoomStatus::Uncleaned),
        candidate('202', kiosk: false),
        candidate('203', active: true),
        candidate('204', reserved: true),
        candidate('205', '2026-11-12 01:00'),
    ]);

    expect(numbers($order))->toBe(['205'])
        ->and($rotation->next([candidate('206', status: RoomStatus::Maintenance)]))->toBeNull();
});
