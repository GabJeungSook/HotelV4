<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The only source of the current time in app/ (TRD §3.4).
 *
 * Bound as a singleton in AppServiceProvider. Tests freeze it with freeze()
 * or by binding a Clock built with a fixed time.
 */
final class Clock
{
    private ?CarbonImmutable $frozen = null;

    public function __construct(?DateTimeInterface $frozenAt = null)
    {
        if ($frozenAt !== null) {
            $this->freeze($frozenAt);
        }
    }

    /** The current moment in UTC. */
    public function now(): CarbonImmutable
    {
        return $this->frozen ?? CarbonImmutable::now('UTC');
    }

    /** The current moment in the display timezone (Asia/Manila). */
    public function local(): CarbonImmutable
    {
        return $this->toLocal($this->now());
    }

    /** Convert a stored UTC time to the display timezone. */
    public function toLocal(DateTimeInterface $time): CarbonImmutable
    {
        return CarbonImmutable::instance($time)->setTimezone(self::displayTimezone());
    }

    /** Today's date in the display timezone, as Y-m-d. */
    public function localDate(): string
    {
        return $this->local()->toDateString();
    }

    /** Stop the clock at a given moment (tests). */
    public function freeze(DateTimeInterface $at): void
    {
        $this->frozen = CarbonImmutable::instance($at)->setTimezone('UTC');
    }

    /** Move a frozen or running clock forward by whole minutes (tests). */
    public function travel(int $minutes): void
    {
        $this->frozen = $this->now()->addMinutes($minutes);
    }

    public function unfreeze(): void
    {
        $this->frozen = null;
    }

    public function isFrozen(): bool
    {
        return $this->frozen !== null;
    }

    public static function displayTimezone(): string
    {
        $timezone = config('hotel.display_timezone', 'Asia/Manila');

        return is_string($timezone) ? $timezone : 'Asia/Manila';
    }
}
