<?php

declare(strict_types=1);

namespace App\Services\Rotation;

use App\Enums\RoomStatus;
use DateTimeInterface;

/** A room as RoomRotation sees it (TRD §4.8). RoomQuery builds these from the rows. */
final class RotationCandidate
{
    public function __construct(
        public readonly int $id,
        public readonly string $number,
        public readonly RoomStatus $status,
        public readonly bool $kioskEnabled,
        public readonly bool $hasActiveStay,
        public readonly bool $hasReservationInPeriod,
        public readonly ?DateTimeInterface $lastCheckoutAt,
    ) {}
}
