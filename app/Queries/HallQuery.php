<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\HallBookingStatus;
use App\Models\HallBooking;
use Carbon\CarbonImmutable;

/** Reads for hall availability (DATABASE_SCHEMA §4.14). */
final class HallQuery
{
    /**
     * Is the hall already booked for any part of [start, end)? A live booking (reserved or
     * completed) whose slot intersects counts; $exceptBookingId skips the booking being edited.
     * Mirrors RoomQuery's reservation-overlap test for rooms.
     */
    public function overlaps(int $hallId, CarbonImmutable $start, CarbonImmutable $end, ?int $exceptBookingId = null): bool
    {
        return HallBooking::query()->withoutGlobalScopes()
            ->where('hall_id', $hallId)
            ->whereIn('status', [HallBookingStatus::Reserved, HallBookingStatus::Completed])
            ->when($exceptBookingId !== null, fn ($q) => $q->whereKeyNot($exceptBookingId))
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->exists();
    }
}
