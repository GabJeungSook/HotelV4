<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\ShiftSession;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A business day (TRD §4.6): every shift of a branch with that business_date, oldest first. Its
 * time window runs from the first shift's opening to the last shift's closing (or now while one
 * is open), so one day ends exactly where the next begins and a late PM shift still belongs to
 * the date it started on. A date with no shifts falls back to the local calendar day.
 */
final class BusinessDay
{
    public function __construct(private readonly Clock $clock) {}

    /** @return Collection<int, ShiftSession> */
    public function shifts(int $branchId, string $date): Collection
    {
        return ShiftSession::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereDate('business_date', $date)
            ->orderBy('opened_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, ShiftSession>|null  $shifts
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function window(int $branchId, string $date, ?Collection $shifts = null): array
    {
        $shifts ??= $this->shifts($branchId, $date);

        if ($shifts->isEmpty()) {
            $start = CarbonImmutable::parse($date, Clock::displayTimezone())->startOfDay()->utc();

            return [$start, $start->addDay()];
        }

        /** @var ShiftSession $first */
        $first = $shifts->first();
        /** @var ShiftSession $last */
        $last = $shifts->last();

        return [$first->opened_at, $last->closed_at ?? $this->clock->now()];
    }

    /**
     * The calendar days of a range: day, the Monday-to-Sunday week of the date, or its month.
     *
     * @return list<string>
     */
    public static function range(string $date, string $range): array
    {
        $d = CarbonImmutable::parse($date);
        [$from, $to] = match ($range) {
            'week' => [$d->startOfWeek(CarbonImmutable::MONDAY), $d->startOfWeek(CarbonImmutable::MONDAY)->addDays(6)],
            'month' => [$d->startOfMonth(), $d->endOfMonth()->startOfDay()],
            default => [$d, $d],
        };

        $days = [];
        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $days[] = $day->toDateString();
        }

        return $days;
    }
}
