<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CleaningStatus;
use App\Enums\CleaningTrigger;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\CleaningTask;
use App\Models\User;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The housekeeper's side of cleaning_tasks (TRD §4.7, APP_FLOW §4.17, DATABASE_SCHEMA §7).
 *
 * queue(): `queued` tasks on the user's floors (floor_user), oldest queued_at first; the first
 * N are startable, N = housekeepers of those floors seen in the last 2 minutes (at least 1).
 * While the user has a task in progress, that task is the only startable item.
 */
final class CleaningQuery
{
    public function __construct(private readonly Clock $clock) {}

    /** @return list<int> */
    public function floorIds(User $user): array
    {
        return array_map('intval', DB::table('floor_user')->where('user_id', $user->id)->pluck('floor_id')->all());
    }

    /** Housekeepers of any of these floors with last_seen_at in the last online_minutes, at least 1. */
    public function online(User $user): int
    {
        $floors = $this->floorIds($user);
        $since = $this->clock->now()->subMinutes((int) config('hotel.housekeeping.online_minutes', 2));

        $count = User::query()
            ->where('branch_id', $user->branch_id)
            ->where('role', Role::Housekeeper)
            ->where('is_active', true)
            ->where('last_seen_at', '>=', $since)
            ->whereExists(fn ($q) => $q->from('floor_user')->whereColumn('floor_user.user_id', 'users.id')->whereIn('floor_user.floor_id', $floors))
            ->count();

        return max(1, $count);
    }

    /** The user's own task in progress, if any (cleaning_tasks_one_per_cleaner allows one). */
    public function inProgress(User $user): ?CleaningTask
    {
        return CleaningTask::query()
            ->where('status', CleaningStatus::InProgress)
            ->where('cleaned_by', $user->id)
            ->first();
    }

    /**
     * Ids of the queued tasks this user may start now, in queue order.
     *
     * @return list<int>
     */
    public function startableIds(User $user): array
    {
        if ($this->inProgress($user) !== null) {
            return [];
        }

        return array_map('intval', $this->queuedQuery($user)->limit($this->online($user))->pluck('cleaning_tasks.id')->all());
    }

    /**
     * Props of the queue (32) and its empty state (35).
     *
     * @return array<string, mixed>
     */
    public function queue(User $user): array
    {
        $branch = Branch::query()->findOrFail($user->branch_id);
        $now = $this->clock->now();
        $online = $this->online($user);
        $current = $this->inProgress($user);

        /** @var list<CleaningTask> $tasks */
        $tasks = $this->queuedQuery($user)->with(['room.roomType'])->get()->all();
        $startable = $current === null ? array_slice(array_map(static fn (CleaningTask $t): int => $t->id, $tasks), 0, $online) : [];

        $rows = [];
        $nextShown = false;
        foreach ($tasks as $task) {
            $canStart = in_array($task->id, $startable, true);
            $waiting = intdiv($now->getTimestamp() - $task->queued_at->getTimestamp(), 60);
            $urgent = $waiting >= $branch->cleaning_urgent_minutes;
            // The first task that is not startable says "Next in line" (32, edge case 39).
            $next = ! $canStart && $current === null && ! $nextShown;
            $nextShown = $nextShown || $next;

            $rows[] = [
                'id' => $task->id,
                'room' => $task->room?->number,
                'type' => $task->room?->roomType?->name,
                'detail' => $this->detail($task, $canStart),
                'waiting_minutes' => $waiting,
                'urgent' => $urgent,
                'tone' => $urgent ? 'bad' : ($waiting >= (int) config('hotel.housekeeping.waiting_warn_minutes', 30) ? 'warn' : 'neutral'),
                'startable' => $canStart,
                'next_in_line' => $next,
            ];
        }

        return [
            'floors' => $this->floorsLabel($user),
            'online' => $online,
            'tasks' => $rows,
            'current' => $current === null ? null : [
                'id' => $current->id,
                'room' => $current->room()->value('number'),
                'started_at' => $current->started_at?->toIso8601String(),
            ],
            'others' => $this->othersInProgress($user),
        ];
    }

    /**
     * Rooms being cleaned right now by other roomboys on the user's floors (read-only awareness, so
     * nobody walks into a room a colleague is already in).
     *
     * @return list<array{room: string|null, by: string, started_at: string|null}>
     */
    public function othersInProgress(User $user): array
    {
        return CleaningTask::query()
            ->select('cleaning_tasks.*', 'users.name as cleaner_name')
            ->join('rooms', 'rooms.id', '=', 'cleaning_tasks.room_id')
            ->join('users', 'users.id', '=', 'cleaning_tasks.cleaned_by')
            ->where('cleaning_tasks.status', CleaningStatus::InProgress)
            ->where('cleaning_tasks.cleaned_by', '!=', $user->id)
            ->whereIn('rooms.floor_id', $this->floorIds($user))
            ->orderBy('cleaning_tasks.started_at')
            ->with('room')
            ->get()
            ->map(fn (CleaningTask $t): array => [
                'room' => $t->room?->number,
                'by' => (string) $t->getAttribute('cleaner_name'),
                'started_at' => $t->started_at?->toIso8601String(),
            ])->all();
    }

    /**
     * Props of the cleaning screen (33).
     *
     * @return array<string, mixed>
     */
    public function task(CleaningTask $task): array
    {
        $branch = Branch::query()->findOrFail($task->branch_id);
        $task->loadMissing('room.roomType');

        return [
            'task' => [
                'id' => $task->id,
                'room' => $task->room?->number,
                'type' => $task->room?->roomType?->name,
                'started_at' => $task->started_at?->toIso8601String(),
                'due_at' => $task->due_at->toIso8601String(),
            ],
            'target_minutes' => $branch->cleaning_target_minutes,
            'checklist' => array_values((array) config('hotel.housekeeping.checklist', [])),
        ];
    }

    /**
     * Props of "My cleans" (34): the user's finished cleans of today (Manila), newest first.
     *
     * @return array<string, mixed>
     */
    public function history(User $user): array
    {
        $branch = Branch::query()->findOrFail($user->branch_id);
        $todayLocal = $this->clock->local()->startOfDay();
        $from = $todayLocal->utc();
        $to = $todayLocal->addDay()->utc();

        $tasks = CleaningTask::query()
            ->with('room')
            ->where('cleaned_by', $user->id)
            ->where('status', CleaningStatus::Done)
            ->where('finished_at', '>=', $from)
            ->where('finished_at', '<', $to)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->get();

        $rows = [];
        $took = [];
        $lateNote = null;
        foreach ($tasks as $task) {
            $minutes = $task->started_at === null || $task->finished_at === null ? null
                : (int) round(($task->finished_at->getTimestamp() - $task->started_at->getTimestamp()) / 60);

            if ($minutes !== null) {
                $took[] = $minutes;
            }

            if ($task->is_late && $lateNote === null && $task->finished_at !== null) {
                $over = intdiv($task->finished_at->getTimestamp() - $task->due_at->getTimestamp(), 60);
                $hours = intdiv($branch->cleaning_deadline_minutes, 60);
                $deadline = $branch->cleaning_deadline_minutes % 60 === 0 ? "{$hours}-hour" : "{$branch->cleaning_deadline_minutes}-minute";
                $lateNote = "Room {$task->room?->number} was finished ".self::span($over)." after its {$deadline} deadline.";
            }

            $rows[] = [
                'id' => $task->id,
                'room' => $task->room?->number,
                'finished_at' => $task->finished_at?->toIso8601String(),
                'took_minutes' => $minutes,
                'late' => $task->is_late,
                'problem' => $task->result?->value === 'problem',
            ];
        }

        return [
            'date' => $todayLocal->toDateString(),
            'cleans' => $rows,
            'count' => count($rows),
            'late' => count(array_filter($rows, static fn (array $r): bool => $r['late'])),
            'average_minutes' => $took === [] ? null : (int) round(array_sum($took) / count($took)),
            'late_note' => $lateNote,
        ];
    }

    /** "48 minutes", "1 hour", "14 hours 50 minutes". */
    private static function span(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        $parts = [];

        if ($h > 0) {
            $parts[] = $h.' '.($h === 1 ? 'hour' : 'hours');
        }

        if ($m > 0 || $h === 0) {
            $parts[] = $m.' '.($m === 1 ? 'minute' : 'minutes');
        }

        return implode(' ', $parts);
    }

    /** "Floors 1–2", "Floor 3", or "No floors assigned". */
    public function floorsLabel(User $user): string
    {
        $numbers = DB::table('floors')->whereIn('id', $this->floorIds($user))->orderBy('number')->pluck('number')->map(static fn ($n): int => (int) $n)->all();

        if ($numbers === []) {
            return 'No floors assigned';
        }

        if (count($numbers) === 1) {
            return "Floor {$numbers[0]}";
        }

        $consecutive = end($numbers) - $numbers[0] === count($numbers) - 1;

        return 'Floors '.($consecutive ? $numbers[0].'–'.end($numbers) : implode(', ', $numbers));
    }

    /** @return Builder<CleaningTask> */
    private function queuedQuery(User $user): Builder
    {
        return CleaningTask::query()
            ->select('cleaning_tasks.*')
            ->join('rooms', 'rooms.id', '=', 'cleaning_tasks.room_id')
            ->where('cleaning_tasks.status', CleaningStatus::Queued)
            ->whereIn('rooms.floor_id', $this->floorIds($user))
            ->orderBy('cleaning_tasks.queued_at')
            ->orderBy('cleaning_tasks.id');
    }

    /** "Standard · checked out 12:04 PM · clean by 4:04 PM" (32). */
    private function detail(CleaningTask $task, bool $withDeadline): string
    {
        $at = $this->time($task->queued_at);
        $parts = [
            $task->room?->roomType?->name,
            $task->trigger_kind === CleaningTrigger::TransferOut ? "transfer-out {$at}" : "checked out {$at}",
        ];

        if ($withDeadline) {
            $parts[] = 'clean by '.$this->time($task->due_at);
        }

        return implode(' · ', array_filter($parts));
    }

    private function time(CarbonImmutable $at): string
    {
        return $this->clock->toLocal($at)->format('g:i A');
    }
}
