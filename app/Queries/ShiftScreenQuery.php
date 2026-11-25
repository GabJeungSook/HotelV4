<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\ApprovalAction;
use App\Enums\CashMovementKind;
use App\Enums\DepositType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\ReasonKind;
use App\Enums\Role;
use App\Enums\RoomStatus;
use App\Enums\ShiftStatus;
use App\Enums\StayState;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\CashCount;
use App\Models\CashCountDraft;
use App\Models\CashMovement;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Room;
use App\Models\ShiftDefinition;
use App\Models\ShiftHandover;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\User;
use App\Services\CashMath;
use App\Services\CurrentShift;
use App\Services\ReportService;
use App\Services\ShiftCalendar;
use App\Support\CashPieces;
use App\Support\Clock;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Props of the shift screens 21–26 (APP_FLOW §4.15, §4.16). The count stays blind: shift cash
 * (21), close step 1 (22) and the count (23) carry no expected cash and no figure it can be
 * derived from (collected amounts, payments, a running drawer total). Expected cash appears only
 * once the shift's cash_counts row exists (24, 25). The step shown is worked out here from the
 * database, so Back, a reload or a second computer always land on the same step.
 */
final class ShiftScreenQuery
{
    public function __construct(
        private readonly StayQuery $stays,
        private readonly StayScreenQuery $screens,
        private readonly CashMath $cash,
        private readonly CurrentShift $currentShift,
        private readonly ShiftCalendar $calendar,
        private readonly Clock $clock,
        private readonly ReportService $reports,
    ) {}

    /** @return array{id: int, period_name: string, business_date: string, status: string} */
    public function header(ShiftSession $shift): array
    {
        return [
            'id' => $shift->id,
            'period_name' => $shift->period_name,
            'business_date' => $shift->business_date->toDateString(),
            'status' => $shift->status->value,
        ];
    }

    /**
     * Shift cash (21): the entries of the open shift (voided ones too), what each form needs,
     * and three plain sums typed on this page. Never the expected drawer total.
     *
     * @return array<string, mixed>
     */
    public function cash(ShiftSession $shift, User $viewer): array
    {
        $movements = CashMovement::query()->withoutGlobalScopes()
            ->with(['creator', 'expenseCategory' => fn ($q) => $q->withoutGlobalScopes(), 'forShift' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('shift_session_id', $shift->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
        $live = $movements->whereNull('voided_at');
        $sum = fn (CashMovementKind $kind): int => (int) $live->where('kind', $kind)->sum(fn (CashMovement $m): int => $m->amount?->centavos() ?? 0);

        return [
            'period' => $this->header($shift),
            'stats' => [
                'opening' => $shift->opening_cash?->centavos() ?? 0,
                'expenses' => $sum(CashMovementKind::Expense),
                'remittance' => $sum(CashMovementKind::Remittance),
                'shortage_payments' => $sum(CashMovementKind::ShortagePayment),
            ],
            'movements' => array_values($movements->map(fn (CashMovement $m): array => [
                'id' => $m->id,
                'occurred_at' => $m->occurred_at->toIso8601String(),
                'kind' => $m->kind->value,
                'category' => $m->expenseCategory?->name,
                'for_shift' => $m->forShift === null ? null : "{$m->forShift->period_name} · {$m->forShift->business_date->toDateString()}",
                'note' => $m->note,
                'by' => $m->creator->name ?? '',
                'amount' => $m->amount?->centavos() ?? 0,
                'voided' => $m->voided_at !== null,
                'void_reason' => $m->void_reason,
            ])->all()),
            'categories' => array_values(ExpenseCategory::query()
                ->where('is_active', true)
                ->orderByRaw("name = 'Other'")
                ->orderBy('name')
                ->get()
                ->map(static fn (ExpenseCategory $c): array => ['id' => $c->id, 'name' => $c->name, 'needs_note' => $c->needs_note, 'needs_manager' => $c->needs_manager])
                ->all()),
            'short_shifts' => $this->shortShifts($shift->branch_id),
            'reasons' => $this->screens->reasons(ReasonKind::VoidCashMovement),
            'approvers' => $this->screens->approvers($shift->branch_id, $viewer),
            'can_void' => $shift->status === ShiftStatus::Open,
        ];
    }

    /**
     * Earlier closed shifts that were short, for a shortage payment. Their variance is part of a
     * closed shift's record (26), so it may be shown.
     *
     * @return list<array{id: int, label: string, short: int}>
     */
    public function shortShifts(int $branchId): array
    {
        return array_values(ShiftSession::query()->withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('status', ShiftStatus::Closed)
            ->where('variance_amount', '<', 0)
            ->orderByDesc('closed_at')
            ->limit(20)
            ->get()
            ->map(static fn (ShiftSession $s): array => [
                'id' => $s->id,
                'label' => "{$s->period_name} · {$s->business_date->toDateString()}".($s->z_no === null ? '' : ' · Z-'.str_pad((string) $s->z_no, 4, '0', STR_PAD_LEFT)),
                'short' => -($s->variance_amount?->centavos() ?? 0),
            ])->all());
    }

    /** Which step of the close guide this shift is on (1–4), from the database only. */
    public function step(ShiftSession $shift): int
    {
        if ($shift->status === ShiftStatus::Open) {
            return 1;
        }

        if (! CashCount::query()->where('shift_session_id', $shift->id)->exists()) {
            return 2;
        }

        return $shift->expected_cash === null ? 3 : 4;
    }

    /**
     * The close guide (22–25): the step and what that step shows. Steps 1 and 2 never carry an
     * amount that hints at the expected cash.
     *
     * @return array<string, mixed>
     */
    public function close(ShiftSession $shift, User $viewer): array
    {
        $step = $this->step($shift);
        $base = [
            'period' => $this->header($shift),
            'step' => $step,
            'closing_by' => $shift->status === ShiftStatus::Closing ? $this->currentShift->closerName($shift->id) : null,
        ];

        return match ($step) {
            1 => [...$base, 'check' => $this->check($shift), 'papers' => $this->papers($shift)],
            2 => [...$base, 'count' => $this->countForm($shift, $viewer)],
            3 => [...$base, 'result' => $this->result($shift)],
            default => [...$base, 'result' => $this->result($shift), 'handover' => $this->handover($shift, $viewer)],
        };
    }

    /**
     * Step 1 (22), "Passed on to the next shift": counts and names, no money.
     *
     * @return list<array{tone: string, label: string, text: string, stay_id?: int}>
     */
    public function check(ShiftSession $shift): array
    {
        $now = $this->clock->now();
        $inHouse = $this->stays->inHouse($shift->branch_id);
        $holds = Stay::query()->withoutGlobalScopes()->where('branch_id', $shift->branch_id)->where('state', StayState::Held)->count();
        $rows = [];

        $rows[] = $holds === 0
            ? ['tone' => 'ok', 'label' => 'OK', 'text' => 'No kiosk holds waiting for payment']
            : ['tone' => 'warn', 'label' => "{$holds} to fix", 'text' => $holds === 1 ? '1 kiosk hold is waiting for payment' : "{$holds} kiosk holds are waiting for payment"];

        foreach ($inHouse as $stay) {
            if ($stay['expected_checkout_at']->lessThan($now)) {
                $late = intdiv($now->getTimestamp() - $stay['expected_checkout_at']->getTimestamp(), 60);
                $rows[] = ['tone' => 'warn', 'label' => '1 to fix', 'text' => "Room {$stay['room_number']} · {$stay['guest_name']} is ".self::minutes($late).' overdue', 'stay_id' => $stay['stay_id']];
            }
        }

        $occupied = count($inHouse);
        $unpaid = count(array_filter($inHouse, static fn (array $s): bool => $s['unpaid']->isPositive()));
        $rows[] = ['tone' => 'info', 'label' => 'Note', 'text' => $occupied === 1 ? '1 room occupied' : "{$occupied} rooms occupied"];
        $rows[] = ['tone' => 'info', 'label' => 'Note', 'text' => $unpaid === 1 ? '1 account has unpaid lines' : "{$unpaid} accounts have unpaid lines"];

        $uncleaned = Room::query()->withoutGlobalScopes()->where('branch_id', $shift->branch_id)->where('status', RoomStatus::Uncleaned)->count();
        $cleaning = Room::query()->withoutGlobalScopes()->where('branch_id', $shift->branch_id)->where('status', RoomStatus::Cleaning)->count();
        $rows[] = ['tone' => 'ok', 'label' => 'OK', 'text' => "{$uncleaned} ".($uncleaned === 1 ? 'room' : 'rooms')." uncleaned, {$cleaning} being cleaned"];

        return $rows;
    }

    /**
     * Step 1 (22), "Have these with you before you count": counts only (TRD §4.12).
     *
     * @return list<array{label: string, count: string}>
     */
    public function papers(ShiftSession $shift): array
    {
        $movements = CashMovement::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->whereNull('voided_at');
        $payments = Payment::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id);
        $voids = Approval::query()->withoutGlobalScopes()
            ->where('branch_id', $shift->branch_id)
            ->whereIn('action', [ApprovalAction::CancelStay, ApprovalAction::VoidLine, ApprovalAction::VoidCashMovement, ApprovalAction::VoidSale])
            ->where('created_at', '>=', $shift->opened_at)
            ->count();
        $keyTaken = (clone $payments)->where('kind', PaymentKind::DepositIn)->where('deposit_type', DepositType::Key)->count();
        $keyReturned = (clone $payments)->whereIn('kind', [PaymentKind::DepositRefund, PaymentKind::DepositApplied])->where('deposit_type', DepositType::Key)->count();

        return [
            ['label' => 'Expense receipts', 'count' => (string) (clone $movements)->where('kind', CashMovementKind::Expense)->count()],
            ['label' => 'Remittance slips', 'count' => (string) (clone $movements)->where('kind', CashMovementKind::Remittance)->count()],
            ['label' => 'GCash and card slips', 'count' => (string) (clone $payments)->whereIn('method', [PaymentMethod::Gcash, PaymentMethod::Card])->count()],
            ['label' => 'Cancelled stays and voids', 'count' => (string) $voids],
            ['label' => 'Key deposits taken / returned', 'count' => "{$keyTaken} / {$keyReturned}"],
        ];
    }

    /**
     * Step 2 (23): the bills, the pieces of a saved draft, who counts and who may witness.
     *
     * @return array<string, mixed>
     */
    public function countForm(ShiftSession $shift, User $viewer): array
    {
        $draft = CashCountDraft::query()->find($shift->id);
        $members = User::query()
            ->join('shift_members', 'shift_members.user_id', '=', 'users.id')
            ->where('shift_members.shift_session_id', $shift->id)
            ->where('users.id', '!=', $viewer->id)
            ->where('users.is_active', true)
            ->pluck('users.id')
            ->all();

        return [
            'bills' => CashPieces::bills(),
            'pieces' => $draft === null ? null : CashPieces::normalize($draft->denominations),
            'counted_by' => $viewer->name,
            'witnesses' => array_values(User::query()
                ->where('is_active', true)
                ->where('id', '!=', $viewer->id)
                ->where(fn ($q) => $q->whereIn('id', $members)->orWhere(fn ($q) => $q->where('branch_id', $shift->branch_id)->whereIn('role', [Role::Supervisor, Role::Admin])))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name])
                ->all()),
            'default_witness' => $members[0] ?? null,
        ];
    }

    /**
     * Step 3 (24), only once the count is saved: expected cash line by line next to the count.
     * The lines add up to CashMath's expected cash (TRD §4.5).
     *
     * @return array<string, mixed>
     */
    public function result(ShiftSession $shift): array
    {
        $count = CashCount::query()->with('counter')->where('shift_session_id', $shift->id)->firstOrFail();
        $payments = Payment::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->where('method', PaymentMethod::Cash)->get();
        $movements = CashMovement::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->whereNull('voided_at')->get();
        $cash = fn (PaymentKind $kind): int => (int) $payments->where('kind', $kind)->sum(fn (Payment $p): int => $p->amount?->centavos() ?? 0);
        $out = fn (CashMovementKind $kind): int => (int) $movements->where('kind', $kind)->sum(fn (CashMovement $m): int => $m->amount?->centavos() ?? 0);

        $lines = [
            ['label' => 'Opening cash', 'amount' => $shift->opening_cash?->centavos() ?? 0],
            ['label' => '+ Collected from guests', 'amount' => $cash(PaymentKind::Payment)],
            ['label' => '+ Deposits taken', 'amount' => $cash(PaymentKind::DepositIn)],
            ['label' => '− Deposits returned', 'amount' => -$cash(PaymentKind::DepositRefund)],
        ];

        if ($cash(PaymentKind::Refund) !== 0) {
            $lines[] = ['label' => '− Given back to guests', 'amount' => -$cash(PaymentKind::Refund)];
        }

        $lines[] = ['label' => '− Expenses', 'amount' => $out(CashMovementKind::Expense)];
        $lines[] = ['label' => '− Remittance', 'amount' => $out(CashMovementKind::Remittance)];

        if ($out(CashMovementKind::ShortagePayment) !== 0) {
            $lines[] = ['label' => '+ Shortage payments', 'amount' => $out(CashMovementKind::ShortagePayment)];
        }

        $expected = $this->cash->expectedFromRows($shift->opening_cash ?? Money::zero(), $payments, $movements);
        $counted = $count->total_cash ?? Money::zero();

        return [
            'lines' => $lines,
            'expected' => $expected->centavos(),
            'counted' => $counted->centavos(),
            'variance' => $this->cash->variance($counted, $expected)->centavos(),
            'counted_by' => ['id' => $count->counted_by, 'name' => $count->counter->name ?? ''],
            'reasons' => $this->screens->reasons(ReasonKind::Variance),
            'saved' => $shift->expected_cash === null ? null : [
                'reason_id' => $shift->variance_reason_id,
                'note' => $shift->variance_note,
            ],
        ];
    }

    /**
     * Step 4 (25): what the incoming front desk takes on, and who may receive or approve. The
     * person who counted is offered as neither (APP_FLOW §6 case 71).
     *
     * @return array<string, mixed>
     */
    public function handover(ShiftSession $shift, User $viewer): array
    {
        $count = CashCount::query()->where('shift_session_id', $shift->id)->firstOrFail();
        $inHouse = $this->stays->inHouse($shift->branch_id);
        $unpaid = array_filter($inHouse, static fn (array $s): bool => $s['unpaid']->isPositive());
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($shift->branch_id);
        $now = $this->clock->now();

        return [
            'counted' => $count->total_cash?->centavos() ?? 0,
            'guests' => count($inHouse),
            'key_deposits' => array_sum(array_map(static fn (array $s): int => $s['key_deposit_held']->centavos(), $inHouse)),
            'unpaid_count' => count($unpaid),
            'unpaid_total' => array_sum(array_map(static fn (array $s): int => $s['unpaid']->centavos(), $unpaid)),
            'stays' => array_map(static fn (array $s): array => [
                'stay_id' => $s['stay_id'],
                'room' => $s['room_number'],
                'guest_name' => $s['guest_name'],
                'expected_checkout_at' => $s['expected_checkout_at']->toIso8601String(),
                'late' => $s['expected_checkout_at']->lessThan($now),
                'unpaid' => $s['unpaid']->centavos(),
            ], $inHouse),
            'next_period' => $this->nextPeriodName($shift),
            'receivers' => array_values(User::query()
                ->where('branch_id', $shift->branch_id)
                ->where('is_active', true)
                ->whereIn('role', [Role::Frontdesk, Role::Supervisor])
                ->whereNotNull('pin_hash')
                ->where('id', '!=', $count->counted_by)
                ->orderByRaw('role = ?', [Role::Supervisor->value])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name])
                ->all()),
            'approvers' => array_values(array_filter(
                $this->screens->approvers($branch->id, $viewer),
                static fn (array $a): bool => $a['id'] !== $count->counted_by,
            )),
        ];
    }

    /**
     * Shift report slip (26): read from the frozen totals, the Z number and the handover. Its
     * money lines are the 80 mm form of the Z builder array (ReportService::slip). Nothing is
     * recomputed.
     *
     * @return array<string, mixed>
     */
    public function report(ShiftSession $shift): array
    {
        $branch = Branch::query()->withoutGlobalScopes()->findOrFail($shift->branch_id);
        $handover = ShiftHandover::query()->with('receiver')->where('from_shift_id', $shift->id)->first();
        $names = User::query()->whereIn('id', array_filter([$shift->opened_by, $shift->closed_by]))->pluck('name', 'id');
        /** @var Collection<int, string> $with */
        $with = User::query()
            ->join('shift_members', 'shift_members.user_id', '=', 'users.id')
            ->where('shift_members.shift_session_id', $shift->id)
            ->whereNotIn('users.id', array_filter([$shift->opened_by]))
            ->orderBy('shift_members.joined_at')
            ->pluck('users.name');

        return [
            'closed' => [
                ...$this->header($shift),
                'z_no' => $shift->z_no,
                'opened_at' => $shift->opened_at->toIso8601String(),
                'closed_at' => $shift->closed_at?->toIso8601String(),
                'opened_by' => $names[$shift->opened_by] ?? '',
                'closed_by' => $shift->closed_by === null ? '' : ($names[$shift->closed_by] ?? ''),
                'with' => $with->values()->all(),
                'is_force_closed' => $shift->is_force_closed,
                'expected' => $shift->expected_cash?->centavos(),
                'counted' => $shift->counted_cash?->centavos(),
                'variance' => $shift->variance_amount?->centavos(),
                'variance_note' => $shift->variance_note,
                'grand_total_before' => $shift->grand_total_before?->centavos(),
                'grand_total_after' => $shift->grand_total_after?->centavos(),
            ],
            'header' => $branch->slip_header,
            'sections' => $this->reports->slip($shift),
            'handover' => $handover === null ? null : [
                'received_by' => $handover->receiver->name ?? null,
                'received_cash' => $handover->received_cash?->centavos(),
                'difference' => $handover->difference_amount?->centavos() ?? 0,
                'note' => $handover->note,
            ],
        ];
    }

    private function nextPeriodName(ShiftSession $shift): string
    {
        $definitions = ShiftDefinition::query()->withoutGlobalScopes()->where('branch_id', $shift->branch_id)->where('is_active', true)->get()->all();
        [$definition] = $this->calendar->following($definitions, $shift->shift_definition_id, $shift->business_date->toDateString());

        return $definition->name;
    }

    private static function minutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes === 1 ? '1 minute' : "{$minutes} minutes";
        }

        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return ($h === 1 ? '1 hour' : "{$h} hours").($m === 0 ? '' : " {$m} min");
    }
}
