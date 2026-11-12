<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RoomStatus;
use App\Services\Rotation\RotationCandidate;

/**
 * Which room the kiosk gives next (TRD §4.8). Pure: candidates in, order out.
 *
 * Only rooms that are available, kiosk-enabled, with no active stay and no reservation
 * arriving within the requested period are eligible. Never-used rooms come first, then the
 * oldest last check-out, then the natural order of the room number.
 */
final class RoomRotation
{
    /**
     * @param  iterable<RotationCandidate>  $rooms
     * @return list<RotationCandidate>
     */
    public function order(iterable $rooms): array
    {
        $eligible = [];

        foreach ($rooms as $room) {
            if ($room->status === RoomStatus::Available && $room->kioskEnabled && ! $room->hasActiveStay && ! $room->hasReservationInPeriod) {
                $eligible[] = $room;
            }
        }

        usort($eligible, static function (RotationCandidate $a, RotationCandidate $b): int {
            if ($a->lastCheckoutAt === null || $b->lastCheckoutAt === null) {
                $byUse = ($a->lastCheckoutAt === null ? 0 : 1) <=> ($b->lastCheckoutAt === null ? 0 : 1);

                if ($byUse !== 0) {
                    return $byUse;
                }
            } else {
                $byTime = $a->lastCheckoutAt->getTimestamp() <=> $b->lastCheckoutAt->getTimestamp();

                if ($byTime !== 0) {
                    return $byTime;
                }
            }

            return strnatcasecmp($a->number, $b->number);
        });

        return $eligible;
    }

    /** @param  iterable<RotationCandidate>  $rooms */
    public function next(iterable $rooms): ?RotationCandidate
    {
        return $this->order($rooms)[0] ?? null;
    }
}
