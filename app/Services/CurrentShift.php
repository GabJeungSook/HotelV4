<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ShiftStatus;
use App\Exceptions\NoOpenShift;
use App\Exceptions\ShiftClosing;
use App\Exceptions\ShiftStale;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ShiftSession;
use App\Support\Clock;

/**
 * The only way to find a branch's open shift (TRD §3.5). Inside an action's transaction the
 * row is locked with FOR UPDATE, so every money row of the action points at it.
 *
 * - for(): the open or closing shift, locked; NoOpenShift if there is none.
 * - forWrite(): refuses with ShiftClosing once the close has started (TRD §4.12).
 * - forPayment(): also refuses with ShiftStale when the shift is shift_stale_block_minutes
 *   past its defined end (payments and charges only).
 */
final class CurrentShift
{
    /** audit_logs.action written when a member starts the close (screen 22). */
    public const CLOSING_STARTED = 'shift.closing';

    public function __construct(
        private readonly Clock $clock,
        private readonly ShiftCalendar $calendar,
    ) {}

    /** The open or closing shift without a lock (reads, screens). */
    public function find(int $branchId): ?ShiftSession
    {
        return ShiftSession::query()->withoutGlobalScopes()->where('open_branch_id', $branchId)->first();
    }

    public function for(int $branchId): ShiftSession
    {
        return ShiftSession::query()->withoutGlobalScopes()
            ->where('open_branch_id', $branchId)
            ->lockForUpdate()
            ->first() ?? throw NoOpenShift::make();
    }

    public function forWrite(int $branchId): ShiftSession
    {
        $shift = $this->for($branchId);

        if ($shift->status === ShiftStatus::Closing) {
            throw ShiftClosing::make($this->closerName($shift->id));
        }

        return $shift;
    }

    /**
     * Who pressed "Next: count cash" (22) on this shift, from the audit_logs row CloseShift
     * writes when the close starts ("Jessa Mae is closing the shift"). The first name only.
     */
    public function closerName(int $shiftId): ?string
    {
        $name = AuditLog::query()
            ->join('users', 'users.id', '=', 'audit_logs.actor_id')
            ->where('audit_logs.action', self::CLOSING_STARTED)
            ->where('audit_logs.subject_type', 'shift_session')
            ->where('audit_logs.subject_id', $shiftId)
            ->orderByDesc('audit_logs.id')
            ->value('users.name');

        if (! is_string($name)) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return count($parts) > 2 ? "{$parts[0]} {$parts[1]}" : ($parts[0] ?? null);
    }

    public function forPayment(int $branchId): ShiftSession
    {
        $shift = $this->forWrite($branchId);

        if ($this->isStale($shift)) {
            throw ShiftStale::make();
        }

        return $shift;
    }

    /** Past its defined end by at least the branch's shift_stale_block_minutes (screen 54). */
    public function isStale(ShiftSession $shift): bool
    {
        $branch = Branch::query()->findOrFail($shift->branch_id);
        $definition = $shift->definition()->withoutGlobalScopes()->firstOrFail();
        $end = $this->calendar->definedEnd($definition, $shift->business_date->toDateString());

        return $this->clock->now()->greaterThanOrEqualTo($end->addMinutes($branch->shift_stale_block_minutes));
    }
}
