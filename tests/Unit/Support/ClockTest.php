<?php

declare(strict_types=1);

use App\Support\Clock;
use Carbon\CarbonImmutable;

it('is bound in the container as a singleton', function (): void {
    expect(app(Clock::class))->toBeInstanceOf(Clock::class)
        ->and(app(Clock::class))->toBe(app(Clock::class));
});

it('returns the current time in UTC', function (): void {
    $now = (new Clock)->now();

    expect($now)->toBeInstanceOf(CarbonImmutable::class)
        ->and($now->getTimezone()->getName())->toBe('UTC');
});

it('freezes, travels and unfreezes', function (): void {
    $clock = new Clock(new DateTimeImmutable('2026-11-12 01:30:00', new DateTimeZone('UTC')));

    expect($clock->isFrozen())->toBeTrue()
        ->and($clock->now()->toDateTimeString())->toBe('2026-11-12 01:30:00');

    $clock->travel(90);
    expect($clock->now()->toDateTimeString())->toBe('2026-11-12 03:00:00');

    $clock->unfreeze();
    expect($clock->isFrozen())->toBeFalse();
});

it('shows times in Asia/Manila', function (): void {
    $clock = new Clock(new DateTimeImmutable('2026-11-12 17:30:00', new DateTimeZone('UTC')));

    expect(Clock::displayTimezone())->toBe('Asia/Manila')
        ->and($clock->local()->format('Y-m-d H:i'))->toBe('2026-11-13 01:30')
        ->and($clock->localDate())->toBe('2026-11-13')
        ->and($clock->toLocal(new DateTimeImmutable('2026-11-12 00:00:00', new DateTimeZone('UTC')))->format('H:i'))->toBe('08:00');
});

it('can be frozen for a whole test through the container', function (): void {
    $this->freezeClock('2026-11-12 04:00:00 UTC');

    expect(app(Clock::class)->now()->toIso8601String())->toBe('2026-11-12T04:00:00+00:00');
});

it('keeps the server on UTC', function (): void {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(date_default_timezone_get())->toBe('UTC');
});
