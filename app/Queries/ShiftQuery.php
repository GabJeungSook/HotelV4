<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ShiftStatus;
use App\Exceptions\ActionRefused;
use App\Models\ShiftDefinition;
use App\Models\ShiftSession;
use App\Services\ShiftCalendar;
use App\Support\Clock;

/** Read side of shifts (DATABASE_SCHEMA §7). */
final class ShiftQuery
{
    public function __construct(
        private readonly Clock $clock,
        private readonly ShiftCalendar $calendar,
    ) {}

    /**
     * The period OpenShift proposes (screen 2), and the last closed shift it follows (null before any close).
     *
     * @return array{definition: ShiftDefinition, business_date: string, last_closed: ShiftSession|null}
     */
    public function proposal(int $branchId): array
    {
        $definitions = ShiftDefinition::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('is_active', true)->get()->all();

        if ($definitions === []) {
            throw ActionRefused::because('This branch has no shift periods set up.');
        }

        $last = ShiftSession::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('status', ShiftStatus::Closed)
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->first();

        [$definition, $date] = $last === null
            ? $this->calendar->containing($definitions, $this->clock->now())
            : $this->calendar->following($definitions, $last->shift_definition_id, $last->business_date->toDateString());

        return ['definition' => $definition, 'business_date' => $date, 'last_closed' => $last];
    }
}
