<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShiftDefinition;
use Carbon\CarbonImmutable;

/**
 * Shift periods and business dates (TRD §4.6). Pure: definitions and times in, periods out.
 *
 * A business date is the local date on which a shift's defined start falls. A PM shift of
 * 20:00–08:00 on 11 Nov runs until 08:00 on 12 Nov and stays the PM shift of 11 Nov.
 */
final class ShiftCalendar
{
    public function __construct(private readonly string $timezone = 'Asia/Manila') {}

    /** The defined start of a period, in UTC. */
    public function definedStart(ShiftDefinition $definition, string $businessDate): CarbonImmutable
    {
        return CarbonImmutable::parse("{$businessDate} {$definition->starts_at}", $this->timezone)->setTimezone('UTC');
    }

    /** The defined end of a period, in UTC; a period whose end is not after its start crosses midnight. */
    public function definedEnd(ShiftDefinition $definition, string $businessDate): CarbonImmutable
    {
        $end = CarbonImmutable::parse("{$businessDate} {$definition->ends_at}", $this->timezone);

        if ($definition->ends_at <= $definition->starts_at) {
            $end = $end->addDay();
        }

        return $end->setTimezone('UTC');
    }

    /**
     * The period that follows a closed one: the next definition by sort order, wrapping to the
     * first definition of the next business date.
     *
     * @param  list<ShiftDefinition>  $definitions  active definitions of the branch
     * @return array{0: ShiftDefinition, 1: string}
     */
    public function following(array $definitions, int $lastDefinitionId, string $lastBusinessDate): array
    {
        $sorted = $this->sorted($definitions);

        foreach ($sorted as $i => $definition) {
            if ($definition->id === $lastDefinitionId) {
                return isset($sorted[$i + 1])
                    ? [$sorted[$i + 1], $lastBusinessDate]
                    : [$sorted[0], CarbonImmutable::parse($lastBusinessDate)->addDay()->toDateString()];
            }
        }

        // The last shift's definition was switched off: start the next date at the first period.
        return [$sorted[0], CarbonImmutable::parse($lastBusinessDate)->addDay()->toDateString()];
    }

    /**
     * The period running at a moment, used when no shift has ever been closed.
     *
     * @param  list<ShiftDefinition>  $definitions
     * @return array{0: ShiftDefinition, 1: string}
     */
    public function containing(array $definitions, CarbonImmutable $at): array
    {
        $sorted = $this->sorted($definitions);
        $today = $at->setTimezone($this->timezone)->startOfDay();

        foreach ([$today->subDay(), $today] as $day) {
            foreach ($sorted as $definition) {
                $date = $day->toDateString();

                if ($at->greaterThanOrEqualTo($this->definedStart($definition, $date)) && $at->lessThan($this->definedEnd($definition, $date))) {
                    return [$definition, $date];
                }
            }
        }

        return [$sorted[0], $today->toDateString()];
    }

    /**
     * @param  list<ShiftDefinition>  $definitions
     * @return list<ShiftDefinition>
     */
    private function sorted(array $definitions): array
    {
        usort($definitions, static fn (ShiftDefinition $a, ShiftDefinition $b): int => [$a->sort_order, $a->id] <=> [$b->sort_order, $b->id]);

        return $definitions;
    }
}
