<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ShiftStatus;
use App\Models\ShiftDefinition;
use App\Models\ShiftMember;
use App\Models\ShiftSession;
use App\Support\Clock;
use Carbon\CarbonImmutable;

/**
 * A week of shifts for the ShiftCalendar (DESIGN_SYSTEM §0; the Shifts card on 61 and the Calendar
 * tab of 37a): days across, one band per shift period, and a block per front desk spanning the days
 * they worked in a row. Reads shift_sessions and shift_members only; no amounts leave here.
 */
final class ShiftCalendarQuery
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @param  string|null  $week  any date in the week to show (Monday to Sunday); this week when null
     * @param  int|null  $userId  only the blocks of this front desk
     * @param  string|null  $band  only this shift period ("AM", "PM")
     * @return array<string, mixed>
     */
    public function week(int $branchId, ?string $week = null, ?int $userId = null, ?string $band = null, ?string $reportRoute = 'reports.shift'): array
    {
        $today = $this->clock->localDate();
        $todayDate = CarbonImmutable::parse($today);
        $anchor = $week !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) === 1 ? CarbonImmutable::parse($week) : $todayDate;
        $monday = $anchor->startOfWeek(CarbonImmutable::MONDAY);
        $sunday = $monday->addDays(6);

        $definitions = ShiftDefinition::query()->withoutGlobalScopes()->where('branch_id', $branchId)->where('is_active', true)->orderBy('sort_order')->get();
        $sessions = ShiftSession::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->whereBetween('business_date', [$monday->toDateString(), $sunday->toDateString()])
            ->orderBy('business_date')->orderBy('opened_at')
            ->get(['id', 'shift_definition_id', 'period_name', 'business_date', 'status', 'opened_at', 'z_no', 'is_force_closed']);
        // One query for the members and their names (the card stays within three queries).
        $members = ShiftMember::query()->join('users', 'users.id', '=', 'shift_members.user_id')
            ->whereIn('shift_members.shift_session_id', $sessions->pluck('id')->all())
            ->orderBy('shift_members.joined_at')
            ->get(['shift_members.*', 'users.name as user_name'])->groupBy('shift_session_id');

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $monday->addDays($i);
            $days[] = ['date' => $d->toDateString(), 'weekday' => $d->format('D'), 'day' => (int) $d->format('j'), 'today' => $d->toDateString() === $today];
        }

        $desks = [];
        $bands = [];
        $nowLocal = $this->clock->local();

        foreach ($definitions as $def) {
            if ($band !== null && $band !== '' && $band !== $def->name) {
                continue;
            }

            // person id => list of [dayIndex, session, member]
            $byPerson = [];
            $opened = [];

            foreach ($sessions->where('shift_definition_id', $def->id) as $s) {
                $index = (int) $monday->diffInDays(CarbonImmutable::parse($s->business_date->toDateString()));
                $opened[$index] = true;

                foreach ($members->get($s->id, collect()) as $m) {
                    $desks[$m->user_id] = (string) $m->getAttribute('user_name');

                    if ($userId !== null && $m->user_id !== $userId) {
                        continue;
                    }

                    $byPerson[$m->user_id][] = ['i' => $index, 's' => $s, 'm' => $m];
                }
            }

            $blocks = [];

            foreach ($byPerson as $personId => $entries) {
                usort($entries, static fn (array $a, array $b): int => $a['i'] <=> $b['i']);
                $run = [array_shift($entries)];

                foreach ($entries as $e) {
                    if ($e['i'] !== $run[count($run) - 1]['i'] + 1) {
                        $blocks[] = $this->block($run, (int) $personId, $reportRoute);
                        $run = [];
                    }
                    $run[] = $e;
                }

                $blocks[] = $this->block($run, (int) $personId, $reportRoute);
            }

            // A dashed block for the days of this week whose shift is not opened yet.
            $todayIndex = $todayDate->betweenIncluded($monday, $sunday) ? (int) $monday->diffInDays($todayDate) : ($todayDate->lessThan($monday) ? 0 : 7);
            $firstFuture = null;
            for ($i = $todayIndex; $i < 7; $i++) {
                if (! isset($opened[$i])) {
                    $firstFuture = $i;
                    break;
                }
            }

            if ($firstFuture !== null && $userId === null) {
                $startsToday = $firstFuture === $todayIndex && $todayDate->betweenIncluded($monday, $sunday);
                $startsAt = CarbonImmutable::parse($today.' '.$def->starts_at, Clock::displayTimezone());
                $label = $startsToday && $startsAt->greaterThan($nowLocal) ? 'Opens '.$startsAt->format('g A') : 'Not opened yet';
                $blocks[] = ['key' => "future-{$def->id}", 'start' => $firstFuture + 1, 'span' => 7 - $firstFuture, 'sub' => $label, 'future' => true];
            }

            if ($blocks === []) {
                continue;
            }

            $bands[] = ['label' => "{$def->name} shift", 'rows' => self::rows($blocks)];
        }

        asort($desks, SORT_NATURAL);

        return [
            'days' => $days,
            'bands' => $bands,
            'week' => $monday->toDateString(),
            'range' => ['from' => $monday->toDateString(), 'to' => $sunday->toDateString()],
            'previous' => $monday->subWeek()->toDateString(),
            'next' => $monday->addWeek()->toDateString(),
            'desks' => array_map(static fn (int $id, string $name): array => ['value' => $id, 'label' => $name], array_keys($desks), array_values($desks)),
            'periods' => $definitions->pluck('name')->values()->all(),
            'open' => $sessions->first(fn (ShiftSession $s): bool => $s->status !== ShiftStatus::Closed)?->period_name,
        ];
    }

    /**
     * One block for a run of consecutive days of one person.
     *
     * @param  non-empty-list<array{i: int, s: ShiftSession, m: ShiftMember}>  $run
     * @return array<string, mixed>
     */
    private function block(array $run, int $personId, ?string $reportRoute): array
    {
        $first = $run[0];
        $last = $run[count($run) - 1];
        $live = $last['s']->status !== ShiftStatus::Closed;
        $count = count($run);
        $zs = array_values(array_filter(array_map(static fn (array $e): ?int => $e['s']->z_no, $run)));
        $z = static fn (int $n): string => 'Z-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        $joinedLate = $live && $count === 1 && $last['m']->joined_at->getTimestamp() - $last['s']->opened_at->getTimestamp() > 300;

        $sub = match (true) {
            $joinedLate => 'joined '.$last['m']->joined_at->setTimezone(Clock::displayTimezone())->format('g:i A'),
            $live => $count === 1 ? 'open now' : "{$count} shifts · open now",
            $zs === [] => $count === 1 ? '1 shift' : "{$count} shifts",
            count($zs) === 1 => $z($zs[0]),
            default => $z($zs[0]).' · '.$z($zs[count($zs) - 1]),
        };

        $forced = array_filter($run, static fn (array $e): bool => $e['s']->is_force_closed) !== [];
        $parts = preg_split('/\s+/', trim((string) $first['m']->getAttribute('user_name'))) ?: [];
        $short = count($parts) > 2 ? "{$parts[0]} {$parts[1]} ".mb_substr($parts[2], 0, 1).'.' : (count($parts) === 2 ? "{$parts[0]} ".mb_substr($parts[1], 0, 1).'.' : ($parts[0] ?? ''));

        return [
            'key' => "{$personId}-{$first['i']}",
            'start' => $first['i'] + 1,
            'span' => $count,
            'name' => $short,
            'sub' => $sub,
            'tone' => $live ? 'ok' : ($forced ? 'bad' : 'primary'),
            'live' => $live,
            'href' => $reportRoute === null ? null : route($reportRoute, $last['s']->id, absolute: false),
        ];
    }

    /**
     * Lays blocks out in rows so that none overlap: each goes into the first row with room.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<list<array<string, mixed>>>
     */
    private static function rows(array $blocks): array
    {
        usort($blocks, static fn (array $a, array $b): int => [(int) ($a['future'] ?? false), $a['start']] <=> [(int) ($b['future'] ?? false), $b['start']]);
        $rows = [];
        $ends = [];

        foreach ($blocks as $b) {
            $placed = false;

            foreach ($ends as $r => $end) {
                if ($b['start'] > $end) {
                    $rows[$r][] = $b;
                    $ends[$r] = $b['start'] + $b['span'] - 1;
                    $placed = true;
                    break;
                }
            }

            if (! $placed) {
                $rows[] = [$b];
                $ends[] = $b['start'] + $b['span'] - 1;
            }
        }

        return $rows;
    }
}
