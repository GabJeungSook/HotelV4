<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Clock;
use DateTimeInterface;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests do not need built assets; the full check builds after testing.
        $this->withoutVite();
    }

    /**
     * Freeze the application clock at a moment (TRD §3.4: tests freeze the clock).
     */
    protected function freezeClock(DateTimeInterface|string $at): Clock
    {
        $clock = new Clock(is_string($at) ? new \DateTimeImmutable($at) : $at);
        $this->app->instance(Clock::class, $clock);

        // Eloquent's own created_at / updated_at follow the frozen clock too.
        Carbon::setTestNow($clock->now());

        return $clock;
    }

    /** Move the frozen clock forward, with Eloquent's timestamps. */
    protected function travelClock(int $minutes): Clock
    {
        $clock = $this->app->make(Clock::class);
        $clock->travel($minutes);
        Carbon::setTestNow($clock->now());

        return $clock;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
