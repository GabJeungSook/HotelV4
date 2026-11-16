<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CleaningStatus;
use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Enums\StayState;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CleaningTask;
use App\Models\DayClosing;
use App\Models\Product;
use App\Models\Reason;
use App\Models\ShiftDefinition;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\User;
use App\Support\Clock;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The one builder of "Needs attention" rows (TRD §4.11, screen 49 and the dashboards 60, 61).
 * Each row has a severity, one sentence, a detail line, how long it has been so, and the route
 * of the screen that fixes it. Nothing here fixes anything (49: "Nothing here is fixed
 * automatically"). Thresholds come from config('hotel.attention').
 *
 * Rows: overdue stays, a shift open too long, a cleaning stuck, failed invariants, a business
 * day not yet closed, a branch with no open shift, a short shift of a day not yet closed,
 * products out of stock or low, and staff who cannot join a shift because they have no PIN.
 *
 * @phpstan-type Row array{kind: string, severity: 'high'|'normal', label: string, title: string, detail: string, since: string|null, since_text: string, route: string, action: array{label: string, type: 'link'|'force_close'|'reset', id: int|null}, branch_id: int|null}
 */
final class AttentionList
{
    private const SINCE_DAYS = 7;

    /** @var array<int, ShiftSession|null> the open shift of each branch, asked once per build */
    private array $open = [];

    public function __construct(
        private readonly Clock $clock,
        private readonly ShiftCalendar $calendar,
    ) {}

    /**
     * Rows for one branch, or for all branches when $branchIds has several (owner). Sorted:
     * high first, then the oldest first.
     *
     * @param  list<int>  $branchIds
     * @return list<Row>
     */
    public function rows(array $branchIds, User $viewer): array
    {
        $branches = Branch::query()->withoutGlobalScopes()->whereIn('id', $branchIds)->orderBy('name')->get();
        $many = $branches->count() > 1;
        $rows = [];

        foreach ($branches as $branch) {
            $prefix = $many ? self::shortName($branch->name).' · ' : '';

            foreach ([
                ...$this->overdueStays($branch, $viewer),
                ...$this->shiftOpenTooLong($branch),
                ...$this->noOpenShift($branch, $many),
                ...$this->cleaningStuck($branch),
                ...$this->daysNotClosed($branch, $viewer),
                ...$this->shortShifts($branch),
                ...$this->stock($branch),
                ...$this->noPin($branch, $viewer),
            ] as $row) {
                $row['detail'] = $prefix.$row['detail'];
                $rows[] = $row;
            }
        }

        array_push($rows, ...$this->invariants());

        usort($rows, static fn (array $a, array $b): int => [$a['severity'] === 'high' ? 0 : 1, $a['since'] ?? '9999'] <=> [$b['severity'] === 'high' ? 0 : 1, $b['since'] ?? '9999']);

        return $rows;
    }

    /** @return list<Row> */
    private function overdueStays(Branch $branch, User $viewer): array
    {
        $limit = $this->now()->subMinutes(self::threshold('stay_overdue_minutes'));
        $canOpen = in_array($viewer->role, [Role::Supervisor, Role::Frontdesk], true);

        return Stay::query()->withoutGlobalScopes()->with('room')
            ->where('branch_id', $branch->id)
            ->where('state', StayState::CheckedIn)
            ->where('expected_checkout_at', '<', $limit)
            ->orderBy('expected_checkout_at')
            ->get()
            ->map(fn (Stay $s): array => $this->row(
                'overdue_stay', 'high', 'Overdue stay',
                'Room '.($s->room->number ?? '—').' overdue '.self::duration($s->expected_checkout_at),
                ($s->room === null ? '' : 'Room '.$s->room->number.' · ').$s->guest_name.' · expected out '.$this->time($s->expected_checkout_at),
                $s->expected_checkout_at,
                $canOpen ? route('stays.show', $s->id, false) : route('admin.exceptions', absolute: false),
                ['label' => 'Open folio', 'type' => 'link', 'id' => $s->id],
                $branch->id,
            ))->values()->all();
    }

    /** @return list<Row> */
    private function shiftOpenTooLong(Branch $branch): array
    {
        $shift = $this->openShift($branch->id);

        if ($shift === null) {
            return [];
        }

        $definition = ShiftDefinition::query()->withoutGlobalScopes()->find($shift->shift_definition_id);

        if ($definition === null) {
            return [];
        }

        $end = $this->calendar->definedEnd($definition, $shift->business_date->toDateString());

        if ($this->now()->lessThan($end->addMinutes(self::threshold('shift_open_past_end_minutes')))) {
            return [];
        }

        $label = "{$shift->period_name} shift of ".$shift->business_date->format('j M');

        return [$this->row(
            'shift_open_too_long', 'normal', 'Shift open too long',
            "{$label} is still open",
            "{$label} still open · opened ".$this->time($shift->opened_at),
            $shift->opened_at,
            route('admin.exceptions', absolute: false),
            ['label' => 'Force close', 'type' => 'force_close', 'id' => $shift->id],
            $branch->id,
        )];
    }

    /** @return list<Row> */
    private function noOpenShift(Branch $branch, bool $many): array
    {
        if ($branch->status->value !== 'active' || $this->openShift($branch->id) !== null) {
            return [];
        }

        $inHouse = Stay::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('state', StayState::CheckedIn)->count();
        $last = ShiftSession::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->whereNotNull('closed_at')->max('closed_at');
        $since = $last === null ? null : CarbonImmutable::parse((string) $last, 'UTC');

        return [$this->row(
            'no_open_shift', $inHouse > 0 ? 'high' : 'normal', 'No open shift',
            ($many ? self::shortName($branch->name) : 'The branch').' has no open shift',
            ($since === null ? 'No shift yet' : 'Since '.$this->time($since)).' · '.$inHouse.' '.($inHouse === 1 ? 'guest' : 'guests').' in house',
            $since,
            route('reports.shifts', absolute: false),
            ['label' => 'See shifts', 'type' => 'link', 'id' => null],
            $branch->id,
        )];
    }

    /** @return list<Row> */
    private function cleaningStuck(Branch $branch): array
    {
        $limit = $this->now()->subMinutes(self::threshold('cleaning_in_progress_minutes'));

        return CleaningTask::query()->withoutGlobalScopes()->with(['room', 'cleaner'])
            ->where('branch_id', $branch->id)
            ->where('status', CleaningStatus::InProgress)
            ->where('started_at', '<', $limit)
            ->orderBy('started_at')
            ->get()
            ->map(fn (CleaningTask $t): array => $this->row(
                'cleaning_stuck', 'normal', 'Cleaning stuck',
                'Cleaning of Room '.($t->room->number ?? '—').' stuck for '.self::duration($t->started_at),
                'Room '.($t->room->number ?? '—').' · started '.$this->time($t->started_at).($t->cleaner === null ? '' : ' by '.$t->cleaner->name),
                $t->started_at,
                route('admin.exceptions', absolute: false),
                ['label' => 'Reset task', 'type' => 'reset', 'id' => $t->id],
                $branch->id,
            ))->values()->all();
    }

    /** @return list<Row> */
    private function daysNotClosed(Branch $branch, User $viewer): array
    {
        $open = $this->openShift($branch->id);
        $before = $open?->business_date->toDateString() ?? $this->clock->localDate();
        $from = CarbonImmutable::parse($before)->subDays(31)->toDateString();

        $shifts = ShiftSession::query()->withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->where('business_date', '>=', $from)->where('business_date', '<', $before)
            ->get(['id', 'business_date', 'status', 'closed_at']);
        $closed = DayClosing::query()->withoutGlobalScopes()->where('branch_id', $branch->id)
            ->where('business_date', '>=', $from)->pluck('business_date')
            ->map(fn ($d): string => substr((string) $d, 0, 10))->flip();
        $canClose = in_array($viewer->role, [Role::Backoffice, Role::Admin, Role::Owner], true);
        $rows = [];

        foreach ($shifts->groupBy(fn (ShiftSession $s): string => $s->business_date->toDateString())->sortKeys() as $date => $day) {
            if ($closed->has($date)) {
                continue;
            }

            $allClosed = $day->every(fn (ShiftSession $s): bool => $s->status === ShiftStatus::Closed);
            $lastClose = $day->max('closed_at');
            $rows[] = $this->row(
                'day_not_closed', 'normal', 'Day not closed',
                CarbonImmutable::parse((string) $date)->format('j M').' is not closed',
                $allClosed ? ($day->count() === 2 ? 'Both shifts are closed' : 'All its shifts are closed') : 'A shift of that day is still open',
                $lastClose === null ? null : CarbonImmutable::instance($lastClose),
                $canClose ? route('reports.daily.close', ['date' => $date], false) : route('reports.daily', ['date' => $date], false),
                ['label' => $canClose ? 'Close the day' : 'Daily sales', 'type' => 'link', 'id' => null],
                $branch->id,
            );
        }

        return $rows;
    }

    /** @return list<Row> */
    private function shortShifts(Branch $branch): array
    {
        $from = CarbonImmutable::parse($this->clock->localDate())->subDays(self::SINCE_DAYS)->toDateString();
        $closedDays = DayClosing::query()->withoutGlobalScopes()->where('branch_id', $branch->id)
            ->where('business_date', '>=', $from)->pluck('business_date')
            ->map(fn ($d): string => substr((string) $d, 0, 10))->all();
        $shifts = ShiftSession::query()->withoutGlobalScopes()
            ->where('branch_id', $branch->id)->where('status', ShiftStatus::Closed)
            ->where('business_date', '>=', $from)->where('variance_amount', '<', 0)
            ->orderBy('closed_at')->get()
            ->reject(fn (ShiftSession $s): bool => in_array($s->business_date->toDateString(), $closedDays, true));
        $reasons = Reason::query()->withoutGlobalScopes()->whereIn('id', $shifts->pluck('variance_reason_id')->filter()->all())->pluck('name', 'id');

        return $shifts->map(fn (ShiftSession $s): array => $this->row(
            'short_shift', 'normal', 'Short',
            "{$s->period_name} shift ".$s->business_date->format('j M').' was short '.Money::ofCentavos(-($s->variance_amount?->centavos() ?? 0))->format(),
            ($reasons[$s->variance_reason_id] ?? 'No reason').' · day not yet closed',
            $s->closed_at,
            route('reports.shift', $s->id, false),
            ['label' => 'Shift report', 'type' => 'link', 'id' => $s->id],
            $branch->id,
        ))->values()->all();
    }

    /** @return list<Row> */
    private function stock(Branch $branch): array
    {
        if (! $branch->pos_enabled) {
            return [];
        }

        $products = Product::query()->withoutGlobalScopes()
            ->where('branch_id', $branch->id)->where('is_active', true)->where('tracks_stock', true)
            ->whereColumn('stock_on_hand', '<=', 'low_stock_at')
            ->orderBy('stock_on_hand')->orderBy('name')
            ->get(['id', 'name', 'stock_on_hand', 'low_stock_at']);

        if ($products->isEmpty()) {
            return [];
        }

        $out = $products->where('stock_on_hand', '<=', 0)->values();
        $low = $products->where('stock_on_hand', '>', 0)->values();
        $lowText = $low->take(2)->map(fn (Product $p): string => "{$p->name} low: {$p->stock_on_hand} left")->implode(' · ');

        $title = $out->isNotEmpty()
            ? $out->first()->name.' is out of stock'.($out->count() > 1 ? ' and '.($out->count() - 1).' more' : '')
            : $low->first()->name.' is running low';
        $detail = $out->isNotEmpty() ? ($lowText !== '' ? $lowText : 'Receive stock on the Stock screen') : "{$low->first()->stock_on_hand} left".($low->count() > 1 ? ' · '.($low->count() - 1).' more running low' : '');

        return [$this->row(
            'stock', 'normal', 'Stock', $title, $detail, null,
            route('reports.inventory', absolute: false),
            ['label' => 'Inventory', 'type' => 'link', 'id' => null],
            $branch->id,
        )];
    }

    /** @return list<Row> */
    private function noPin(Branch $branch, User $viewer): array
    {
        $canFix = in_array($viewer->role, [Role::Admin, Role::Owner], true);

        return User::query()
            ->where('branch_id', $branch->id)->where('is_active', true)->whereNull('pin_hash')
            ->whereIn('role', [Role::Frontdesk, Role::Supervisor, Role::Admin])
            ->orderBy('name')->get(['id', 'name', 'created_at'])
            ->map(fn (User $u): array => $this->row(
                'no_pin', 'normal', 'No PIN',
                "{$u->name} has no PIN",
                'Cannot join a shift or approve until a PIN is set',
                $u->created_at === null ? null : CarbonImmutable::instance($u->created_at),
                $canFix ? route('admin.users', absolute: false) : route('admin.exceptions', absolute: false),
                ['label' => 'Users', 'type' => 'link', 'id' => $u->id],
                $branch->id,
            ))->values()->all();
    }

    /**
     * Failed invariants from the last nightly check (TRD §8): never fixed automatically.
     *
     * @return list<Row>
     */
    private function invariants(): array
    {
        return AuditLog::query()->where('action', 'invariant.failed')
            ->where('created_at', '>=', $this->now()->subHours(26))
            ->orderBy('created_at')->get()
            ->map(fn (AuditLog $log): array => $this->row(
                'invariant_failed', 'high', 'Check failed',
                'A data check failed',
                (string) ($log->after_json['message'] ?? 'Invariant '.$log->subject_id),
                $log->created_at,
                route('reports.audit', absolute: false),
                ['label' => 'Audit trail', 'type' => 'link', 'id' => null],
                $log->branch_id,
            ))->values()->all();
    }

    private function openShift(int $branchId): ?ShiftSession
    {
        if (! array_key_exists($branchId, $this->open)) {
            $this->open[$branchId] = ShiftSession::query()->withoutGlobalScopes()
                ->where('branch_id', $branchId)
                ->whereIn('status', [ShiftStatus::Open, ShiftStatus::Closing])
                ->first();
        }

        return $this->open[$branchId];
    }

    /**
     * @param  'high'|'normal'  $severity
     * @param  array{label: string, type: 'link'|'force_close'|'reset', id: int|null}  $action
     * @return Row
     */
    private function row(string $kind, string $severity, string $label, string $title, string $detail, ?\DateTimeInterface $since, string $route, array $action, ?int $branchId): array
    {
        return [
            'kind' => $kind,
            'severity' => $severity,
            'label' => $label,
            'title' => $title,
            'detail' => $detail,
            'since' => $since === null ? null : CarbonImmutable::instance($since)->utc()->toIso8601String(),
            'since_text' => $since === null ? '—' : self::duration($since, $this->now()),
            'route' => $route,
            'action' => $action,
            'branch_id' => $branchId,
        ];
    }

    private function now(): CarbonImmutable
    {
        return $this->clock->now();
    }

    private function time(\DateTimeInterface $at): string
    {
        return $this->clock->toLocal($at)->format('g:i A');
    }

    /** "42 min", "3 h 12 m", "2 d 4 h" since a moment. */
    public static function duration(\DateTimeInterface $since, ?CarbonImmutable $now = null): string
    {
        $now ??= app(Clock::class)->now();
        $minutes = max(0, intdiv($now->getTimestamp() - $since->getTimestamp(), 60));

        if ($minutes < 60) {
            return "{$minutes} min";
        }

        if ($minutes < 1440) {
            return intdiv($minutes, 60).' h '.($minutes % 60).' m';
        }

        return intdiv($minutes, 1440).' d '.intdiv($minutes % 1440, 60).' h';
    }

    /** "Dalisay GenSan" → "GenSan", for rows that name their branch on the owner's list. */
    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [$name];

        return count($parts) > 1 ? (string) end($parts) : $name;
    }

    private static function threshold(string $key): int
    {
        $value = config("hotel.attention.{$key}");

        return is_numeric($value) ? (int) $value : 60;
    }
}
