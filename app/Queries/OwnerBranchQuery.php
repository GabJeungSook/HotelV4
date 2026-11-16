<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\BranchStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\DailySummary;
use App\Models\Room;
use App\Models\ShiftDefinition;
use App\Models\ShiftSession;
use App\Models\User;
use App\Services\ShiftCalendar;
use App\Support\Clock;

/**
 * Read side of the owner's branch screens (55, 56). Today's sales come from the summary table
 * (`daily_summaries.net_sales_amount` of the branch's current business date), never from the
 * ledgers (CLAUDE.md rule 8). The current business date is the open shift's, otherwise the
 * period running now by the branch's shift definitions (TRD §4.6), otherwise the local date.
 */
final class OwnerBranchQuery
{
    public function __construct(
        private readonly Clock $clock,
        private readonly ShiftCalendar $calendar,
    ) {}

    /**
     * @return list<array{id: int, name: string, address: string, code_prefix: string, status: string, status_label: string, rooms: int, staff: int, shift: array{period_name: string, status: string, opened_at: string|null}|null, business_date: string, today_net_sales: int|null}>
     */
    public function list(): array
    {
        $branches = Branch::query()->withoutGlobalScopes()->orderBy('id')->get();
        $ids = $branches->pluck('id')->all();

        $rooms = Room::query()->withoutGlobalScopes()->whereIn('branch_id', $ids)->where('is_active', true)
            ->selectRaw('branch_id, count(*) as n')->groupBy('branch_id')->pluck('n', 'branch_id');
        $staff = User::query()->whereIn('branch_id', $ids)->where('is_active', true)->where('role', '!=', Role::Kiosk)
            ->selectRaw('branch_id, count(*) as n')->groupBy('branch_id')->pluck('n', 'branch_id');
        $open = ShiftSession::query()->withoutGlobalScopes()->whereIn('open_branch_id', $ids)->get()->keyBy('branch_id');
        $definitions = ShiftDefinition::query()->withoutGlobalScopes()->whereIn('branch_id', $ids)->where('is_active', true)->get()->groupBy('branch_id');

        $dates = [];
        foreach ($branches as $branch) {
            $shift = $open->get($branch->id);
            $dates[$branch->id] = $shift !== null
                ? $shift->business_date->toDateString()
                : $this->businessDate($definitions->get($branch->id)?->all() ?? []);
        }

        $sales = [];
        $rows = DailySummary::query()->withoutGlobalScopes()->whereIn('branch_id', $ids)
            ->whereIn('business_date', array_values(array_unique($dates)))->toBase()->get(['branch_id', 'business_date', 'net_sales_amount']);
        foreach ($rows as $row) {
            if (substr((string) $row->business_date, 0, 10) === $dates[(int) $row->branch_id]) {
                $sales[(int) $row->branch_id] = (int) $row->net_sales_amount;
            }
        }

        return $branches->map(function (Branch $branch) use ($rooms, $staff, $open, $dates, $sales): array {
            $settingUp = $branch->status === BranchStatus::SettingUp;
            /** @var ShiftSession|null $shift */
            $shift = $open->get($branch->id);

            return [
                'id' => $branch->id,
                'name' => $branch->name,
                'address' => $branch->address,
                'code_prefix' => $branch->code_prefix,
                'status' => $branch->status->value,
                'status_label' => $branch->status->label(),
                'rooms' => (int) ($rooms[$branch->id] ?? 0),
                'staff' => (int) ($staff[$branch->id] ?? 0),
                'shift' => $shift === null ? null : [
                    'period_name' => $shift->period_name,
                    'status' => $shift->status->value,
                    'opened_at' => $shift->opened_at->toIso8601String(),
                ],
                'business_date' => $dates[$branch->id],
                'today_net_sales' => $settingUp ? null : (int) ($sales[$branch->id] ?? 0),
            ];
        })->values()->all();
    }

    /**
     * Branches to copy from on 56.
     *
     * @return list<array{value: int, label: string}>
     */
    public function copySources(): array
    {
        return Branch::query()->withoutGlobalScopes()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Branch $b): array => ['value' => $b->id, 'label' => $b->name])->values()->all();
    }

    /** @param  list<ShiftDefinition>  $definitions */
    private function businessDate(array $definitions): string
    {
        if ($definitions === []) {
            return $this->clock->localDate();
        }

        return $this->calendar->containing($definitions, $this->clock->now())[1];
    }
}
