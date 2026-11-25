<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CashMovementKind;
use App\Enums\PaymentKind;
use App\Enums\PosMode;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\CleaningTask;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\AttentionList;
use App\Services\Reports\BusinessDay;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The activity log (admin and superadmin): every action of a business day in one read-only feed —
 * sign-ins and sign-outs, check-ins and check-outs, payments and deposits, POS sales, expenses and
 * remittances, housekeeping, and every admin/owner/setup/approval/shift change (which already live
 * in audit_logs). It is a read of what is already recorded, so no action writes a second time and
 * the "money written once" rule stands. Most recent first; filterable by person.
 *
 * @phpstan-type Item array{id: string, at: string, by: string, branch: string, action: string, detail: string}
 */
final class ActivityQuery
{
    public function __construct(private readonly BusinessDay $days) {}

    /**
     * @param  list<int>  $branchIds
     * @return list<Item>
     */
    public function feed(array $branchIds, string $date, ?int $userId = null): array
    {
        if ($branchIds === []) {
            return [];
        }

        [$start, $end] = $this->days->window($branchIds[0], $date);
        $names = User::query()->withoutGlobalScopes()->pluck('name', 'id');
        $many = count($branchIds) > 1;
        $branchNames = $many ? Branch::query()->withoutGlobalScopes()->whereIn('id', $branchIds)->pluck('name', 'id') : collect();
        $rooms = Room::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->pluck('number', 'id');

        /** @var list<Item> $rows */
        $rows = [];
        $add = function (string $id, ?\DateTimeInterface $at, ?int $by, ?int $branchId, string $action, string $detail) use (&$rows, $names, $branchNames, $many): void {
            if ($at === null) {
                return;
            }

            $rows[] = [
                'id' => $id,
                'at' => CarbonImmutable::instance($at)->toIso8601String(),
                'by' => $by === null ? '' : (string) ($names[$by] ?? ''),
                'branch' => $many && $branchId !== null ? AttentionList::shortName((string) ($branchNames[$branchId] ?? '')) : '',
                'action' => $action,
                'detail' => $detail,
            ];
        };
        $room = static fn (?int $id): string => $id === null ? '' : 'Room '.($rooms[$id] ?? '—');

        // Admin, owner, setup, approvals, shift/day closes, PIN unlocks and sign-ins (audit_logs).
        AuditLog::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->whereBetween('created_at', [$start, $end])
            ->when($userId !== null, fn ($q) => $q->where('actor_id', $userId))
            ->orderBy('id')->get()
            ->each(fn (AuditLog $l) => $add('audit-'.$l->id, $l->created_at, $l->actor_id, $l->branch_id, self::auditLabel($l->action), self::auditDetail($l)));

        // Supervisor-PIN approvals (void, cancel, transfer, force close, redo count, …): who did it,
        // and whose PIN approved it — so a PIN used without permission is on the record.
        Approval::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->whereBetween('created_at', [$start, $end])
            ->when($userId !== null, fn ($q) => $q->where(fn ($w) => $w->where('requested_by', $userId)->orWhere('approved_by', $userId)))
            ->get()
            ->each(fn (Approval $a) => $add(
                'approval-'.$a->id,
                $a->created_at,
                $a->requested_by,
                $a->branch_id,
                $a->action->label(),
                'PIN approved by '.(string) ($names[$a->approved_by] ?? '—').($a->summary ? ' · '.$a->summary : ''),
            ));

        // Check-ins and check-outs.
        Stay::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->whereBetween('checked_in_at', [$start, $end])
            ->when($userId !== null, fn ($q) => $q->where('checked_in_by', $userId))
            ->get(['id', 'branch_id', 'room_id', 'guest_name', 'checked_in_at', 'checked_in_by'])
            ->each(fn (Stay $s) => $add('checkin-'.$s->id, $s->checked_in_at, $s->checked_in_by, $s->branch_id, 'Checked in', trim($room($s->room_id).' · '.$s->guest_name, ' ·')));
        Stay::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->whereBetween('checked_out_at', [$start, $end])
            ->when($userId !== null, fn ($q) => $q->where('checked_out_by', $userId))
            ->get(['id', 'branch_id', 'room_id', 'guest_name', 'checked_out_at', 'checked_out_by'])
            ->each(fn (Stay $s) => $add('checkout-'.$s->id, $s->checked_out_at, $s->checked_out_by, $s->branch_id, 'Checked out', trim($room($s->room_id).' · '.$s->guest_name, ' ·')));

        // Payments and deposits.
        Payment::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->whereBetween('occurred_at', [$start, $end])
            ->when($userId !== null, fn ($q) => $q->where('created_by', $userId))
            ->get()
            ->each(fn (Payment $p) => $add('pay-'.$p->id, $p->occurred_at, $p->created_by, $p->branch_id, self::paymentLabel($p->kind), ($p->amount ?? Money::zero())->abs()->format().' · '.$p->method->label()));

        // POS sales.
        PosOrder::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->whereBetween('occurred_at', [$start, $end])
            ->when($userId !== null, fn ($q) => $q->where('created_by', $userId))
            ->get()
            ->each(fn (PosOrder $o) => $add('pos-'.$o->id, $o->occurred_at, $o->created_by, $o->branch_id, 'POS sale', ($o->total_amount?->format() ?? '').' · '.($o->mode === PosMode::WalkIn ? 'walk-in' : 'to room')));

        // Cash in and out of the drawer.
        CashMovement::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->whereBetween('occurred_at', [$start, $end])->whereNull('voided_at')
            ->when($userId !== null, fn ($q) => $q->where('created_by', $userId))
            ->get()
            ->each(fn (CashMovement $m) => $add('cash-'.$m->id, $m->occurred_at, $m->created_by, $m->branch_id, self::movementLabel($m->kind), $m->amount?->format() ?? ''));

        // Housekeeping: cleans started and finished.
        CleaningTask::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->whereBetween('started_at', [$start, $end])
            ->when($userId !== null, fn ($q) => $q->where('cleaned_by', $userId))
            ->get(['id', 'branch_id', 'room_id', 'started_at', 'cleaned_by'])
            ->each(fn (CleaningTask $t) => $add('clean-start-'.$t->id, $t->started_at, $t->cleaned_by, $t->branch_id, 'Started cleaning', $room($t->room_id)));
        CleaningTask::query()->withoutGlobalScopes()->whereIn('branch_id', $branchIds)->whereBetween('finished_at', [$start, $end])
            ->when($userId !== null, fn ($q) => $q->where('cleaned_by', $userId))
            ->get(['id', 'branch_id', 'room_id', 'finished_at', 'cleaned_by'])
            ->each(fn (CleaningTask $t) => $add('clean-done-'.$t->id, $t->finished_at, $t->cleaned_by, $t->branch_id, 'Finished cleaning', $room($t->room_id)));

        usort($rows, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_slice($rows, 0, 1000);
    }

    /**
     * The people for the "done by" filter.
     *
     * @param  list<int>  $branchIds
     * @return list<array{id: int, name: string}>
     */
    public function people(array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        return User::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q->whereIn('branch_id', $branchIds)->orWhereNull('branch_id'))
            ->orderBy('name')->get(['id', 'name'])
            ->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all();
    }

    private static function auditLabel(string $action): string
    {
        return match ($action) {
            'auth.login' => 'Signed in',
            'auth.logged_out' => 'Signed out',
            'auth.switched' => 'Switched user',
            'shift.closed' => 'Shift closed',
            'shift.closing' => 'Started the close',
            'shift.closing_stopped' => 'Stopped the close',
            'shift.count_reopened' => 'Count reopened',
            'day.closed' => 'Day closed',
            'pin.unlocked' => 'PIN unlocked',
            'reconcile.mismatch' => 'Reconcile mismatch',
            'invariant.failed' => 'Nightly check failed',
            'cleaning.reset' => 'Cleaning reset',
            default => ucfirst(str_replace(['.', '_'], [' · ', ' '], $action)),
        };
    }

    private static function auditDetail(AuditLog $log): string
    {
        $after = is_array($log->after_json) ? $log->after_json : [];
        $name = $after['name'] ?? $after['label'] ?? $after['rate'] ?? null;

        return is_string($name) ? $name : '';
    }

    private static function paymentLabel(PaymentKind $kind): string
    {
        return match ($kind) {
            PaymentKind::Payment => 'Payment taken',
            PaymentKind::DepositIn => 'Deposit taken',
            PaymentKind::DepositRefund => 'Deposit returned',
            PaymentKind::DepositApplied => 'Paid from deposit',
            PaymentKind::Refund => 'Refund given',
        };
    }

    private static function movementLabel(CashMovementKind $kind): string
    {
        return match ($kind) {
            CashMovementKind::Expense => 'Expense',
            CashMovementKind::Remittance => 'Remittance',
            CashMovementKind::ShortagePayment => 'Shortage payment',
        };
    }
}
