<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;

/**
 * A dashboard period (60, 61) for one branch: its business dates, the dates of the period it is
 * compared with, and the words each card uses (TRD §4.11 "Each card states its period"). The
 * comparison is the same-length period immediately before; "today" is compared with yesterday
 * up to the same time of day (DashboardService cuts that one by hour).
 */
final readonly class Period
{
    public const KEYS = ['today', 'yesterday', 'week', 'month', 'year'];

    public function __construct(
        public string $key,
        public string $from,
        public string $to,
        public string $previousFrom,
        public string $previousTo,
    ) {}

    /** @param  string  $today  the branch's current business date */
    public static function of(string $key, string $today): self
    {
        $key = in_array($key, self::KEYS, true) ? $key : 'today';
        $t = CarbonImmutable::parse($today);

        [$from, $to] = match ($key) {
            'yesterday' => [$t->subDay(), $t->subDay()],
            'week' => [$t->startOfWeek(CarbonImmutable::MONDAY), $t],
            'month' => [$t->startOfMonth(), $t],
            'year' => [$t->startOfYear(), $t],
            default => [$t, $t],
        };

        $days = (int) $from->diffInDays($to) + 1;

        return new self($key, $from->toDateString(), $to->toDateString(), $from->subDays($days)->toDateString(), $from->subDay()->toDateString());
    }

    /** "today", "yesterday", "this week", … for card labels ("Sales today", "Short · this week"). */
    public function words(): string
    {
        return match ($this->key) {
            'yesterday' => 'yesterday',
            'week' => 'this week',
            'month' => 'this month',
            'year' => 'this year',
            default => 'today',
        };
    }

    /** What the change figure is compared with. */
    public function comparedWith(): string
    {
        return match ($this->key) {
            'today' => 'vs yesterday by this time',
            'yesterday' => 'vs the day before',
            default => 'vs the '.$this->days().' days before',
        };
    }

    public function days(): int
    {
        return (int) CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($this->to)) + 1;
    }
}
