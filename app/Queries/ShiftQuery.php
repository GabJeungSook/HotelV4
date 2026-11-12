<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Exceptions\ActionRefused;
use App\Models\Branch;
use App\Models\ShiftDefinition;
use App\Models\ShiftMember;
use App\Models\ShiftSession;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\ShiftCalendar;
use App\Support\Clock;

/** Read side of shifts (DATABASE_SCHEMA §7). */
final class ShiftQuery
{
    public function __construct(
        private readonly Clock $clock,
        private readonly ShiftCalendar $calendar,
        private readonly CurrentShift $currentShift,
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

    /** The branch's open or closing shift, unlocked (screens, middleware). */
    public function open(int $branchId): ?ShiftSession
    {
        return $this->currentShift->find($branchId);
    }

    public function isMember(int $shiftId, int $userId): bool
    {
        return ShiftMember::query()->where('shift_session_id', $shiftId)->where('user_id', $userId)->exists();
    }

    /**
     * The shared `shift` prop (SYSTEM_DESIGN §4.3): who is on it and how late it is. Never
     * expected cash, collected amounts or anything they can be derived from.
     *
     * @return array{id: int, period_name: string, business_date: string, status: string, opened_at: string|null, defined_end: string, minutes_past_end: int, overdue: bool, stale: bool, members: list<array{id: int, name: string}>}|null
     */
    public function current(int $branchId): ?array
    {
        $shift = $this->open($branchId);

        if ($shift === null) {
            return null;
        }

        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($branchId);
        $definition = ShiftDefinition::query()->withoutGlobalScopes()->findOrFail($shift->shift_definition_id);
        $end = $this->calendar->definedEnd($definition, $shift->business_date->toDateString());
        $past = intdiv($this->clock->now()->getTimestamp() - $end->getTimestamp(), 60);

        return [
            'id' => $shift->id,
            'period_name' => $shift->period_name,
            'business_date' => $shift->business_date->toDateString(),
            'status' => $shift->status->value,
            'opened_at' => $shift->opened_at->toIso8601String(),
            'defined_end' => $end->toIso8601String(),
            'minutes_past_end' => $past,
            'overdue' => $past >= $branch->shift_overdue_warn_minutes,
            'stale' => $past >= $branch->shift_stale_block_minutes,
            'members' => $this->members($shift->id),
        ];
    }

    /** @return list<array{id: int, name: string}> */
    public function members(int $shiftId): array
    {
        return array_values(User::query()
            ->join('shift_members', 'shift_members.user_id', '=', 'users.id')
            ->where('shift_members.shift_session_id', $shiftId)
            ->orderBy('shift_members.joined_at')
            ->orderBy('shift_members.id')
            ->get(['users.id', 'users.name'])
            ->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name])
            ->all());
    }

    /**
     * Screen 2: the proposed period, the other periods, the cash the last shift counted and who
     * closed it. Screen 2b: the open shift, who opened it and how many front desks are on it.
     * No money of the open shift is ever in here.
     *
     * @return array<string, mixed>
     */
    public function openScreen(int $branchId): array
    {
        $open = $this->open($branchId);
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($branchId);

        if ($open !== null) {
            $frontdesks = User::query()
                ->join('shift_members', 'shift_members.user_id', '=', 'users.id')
                ->where('shift_members.shift_session_id', $open->id)
                ->where('users.role', Role::Frontdesk)
                ->count();

            return [
                'mode' => 'join',
                'shift' => [
                    'id' => $open->id,
                    'period_name' => $open->period_name,
                    'business_date' => $open->business_date->toDateString(),
                    'opened_at' => $open->opened_at->toIso8601String(),
                    'opened_by' => User::query()->find($open->opened_by)?->name,
                    'status' => $open->status->value,
                ],
                'members' => $this->members($open->id),
                'frontdesks' => $frontdesks,
                'max_frontdesks' => $branch->max_frontdesks_per_shift,
            ];
        }

        ['definition' => $proposed, 'business_date' => $date, 'last_closed' => $last] = $this->proposal($branchId);
        $definitions = ShiftDefinition::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'mode' => 'open',
            'proposal' => [
                'definition_id' => $proposed->id,
                'business_date' => $date,
                'last_period_name' => $last?->period_name,
                'last_business_date' => $last?->business_date->toDateString(),
                'last_closed_at' => $last?->closed_at?->toIso8601String(),
            ],
            'definitions' => array_values($definitions->map(fn (ShiftDefinition $d): array => [
                'id' => $d->id,
                'name' => $d->name,
                'start' => $this->calendar->definedStart($d, $date)->toIso8601String(),
                'end' => $this->calendar->definedEnd($d, $date)->toIso8601String(),
            ])->all()),
            'can_change_freely' => $last === null,
            'last_counted' => $last?->counted_cash?->centavos(),
            'closed_by' => $last === null ? null : User::query()->find($last->closed_by)?->name,
        ];
    }
}
