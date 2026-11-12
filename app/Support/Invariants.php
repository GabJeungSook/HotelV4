<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\RoomStatus;
use App\Enums\ShiftStatus;
use App\Enums\StayState;
use App\Models\ShiftForwardedStay;
use App\Models\ShiftSession;
use App\Services\CashMath;
use App\Services\ReportService;
use Illuminate\Support\Facades\DB;

/**
 * The data invariants of TRD §8, items 1–8. Each check returns one sentence per broken row;
 * an empty list means the data is consistent. Shared by the CheckInvariants command (Phase 8)
 * and by the hook that runs after every Feature test. It never fixes anything.
 */
final class Invariants
{
    /** @return list<string> */
    public static function check(): array
    {
        return [
            ...self::oneOpenShiftPerBranch(),
            ...self::occupiedRoomsMatchStays(),
            ...self::checkedInStaysHaveARoomLine(),
            ...self::checkedOutStaysAreSettled(),
            ...self::stockMatchesLastMovement(),
            ...self::closedShiftTotalsHold(),
            ...self::businessDaysAgree(),
            ...self::forwardedChainHolds(),
        ];
    }

    /**
     * 1. At most one open shift per branch.
     *
     * @return list<string>
     */
    public static function oneOpenShiftPerBranch(): array
    {
        return DB::table('shift_sessions')
            ->whereIn('status', [ShiftStatus::Open->value, ShiftStatus::Closing->value])
            ->groupBy('branch_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('branch_id')
            ->map(fn (mixed $branch): string => "1. Branch {$branch} has more than one open shift.")
            ->values()
            ->all();
    }

    /**
     * 2. Every occupied room has exactly one checked-in stay pointing at it, and every
     * checked-in stay's room is occupied.
     *
     * @return list<string>
     */
    public static function occupiedRoomsMatchStays(): array
    {
        $problems = [];

        $occupiedWithout = DB::table('rooms')
            ->where('status', RoomStatus::Occupied->value)
            ->whereNotExists(fn ($q) => $q->from('stays')->whereColumn('stays.room_id', 'rooms.id')->where('stays.state', StayState::CheckedIn->value))
            ->pluck('number');
        foreach ($occupiedWithout as $number) {
            $problems[] = "2. Room {$number} is occupied but no checked-in stay points at it.";
        }

        $doubled = DB::table('stays')->where('state', StayState::CheckedIn->value)->groupBy('room_id')->havingRaw('COUNT(*) > 1')->pluck('room_id');
        foreach ($doubled as $roomId) {
            $problems[] = "2. Room id {$roomId} has more than one checked-in stay.";
        }

        $notOccupied = DB::table('stays')
            ->join('rooms', 'rooms.id', '=', 'stays.room_id')
            ->where('stays.state', StayState::CheckedIn->value)
            ->where('rooms.status', '<>', RoomStatus::Occupied->value)
            ->get(['stays.code', 'rooms.number', 'rooms.status']);
        foreach ($notOccupied as $row) {
            $problems[] = "2. Stay {$row->code} is checked in but room {$row->number} is {$row->status}.";
        }

        return $problems;
    }

    /**
     * 3. No checked-in stay without a room folio line.
     *
     * @return list<string>
     */
    public static function checkedInStaysHaveARoomLine(): array
    {
        return DB::table('stays')
            ->where('state', StayState::CheckedIn->value)
            ->whereNotExists(fn ($q) => $q->from('folio_lines')->whereColumn('folio_lines.stay_id', 'stays.id')->where('folio_lines.kind', LineKind::Room->value))
            ->pluck('code')
            ->map(fn (mixed $code): string => "3. Stay {$code} is checked in without a room line.")
            ->values()
            ->all();
    }

    /**
     * 4. Every checked-out stay has balance 0 and deposit held 0.
     *
     * @return list<string>
     */
    public static function checkedOutStaysAreSettled(): array
    {
        $balances = DB::table('stays')
            ->where('stays.state', StayState::CheckedOut->value)
            ->selectRaw('stays.code,
                COALESCE((SELECT SUM(fl.amount) FROM folio_lines fl WHERE fl.stay_id = stays.id), 0)
              - COALESCE((SELECT SUM(pa.amount) FROM payment_allocations pa JOIN folio_lines f2 ON f2.id = pa.folio_line_id WHERE f2.stay_id = stays.id), 0) AS balance,
                COALESCE((SELECT SUM(CASE WHEN p.kind = ? THEN -p.amount ELSE p.amount END) FROM payments p WHERE p.stay_id = stays.id AND p.deposit_type IS NOT NULL), 0) AS held', [PaymentKind::DepositApplied->value])
            ->get();

        $problems = [];

        foreach ($balances as $row) {
            if ((int) $row->balance !== 0) {
                $problems[] = "4. Stay {$row->code} is checked out with a balance of {$row->balance}.";
            }

            if ((int) $row->held !== 0) {
                $problems[] = "4. Stay {$row->code} is checked out with a deposit of {$row->held} still held.";
            }
        }

        return $problems;
    }

    /**
     * 5. products.stock_on_hand equals the last stock_movements.balance_after.
     *
     * @return list<string>
     */
    public static function stockMatchesLastMovement(): array
    {
        $rows = DB::table('products')
            ->whereExists(fn ($q) => $q->from('stock_movements')->whereColumn('stock_movements.product_id', 'products.id'))
            ->selectRaw('products.name, products.stock_on_hand,
                (SELECT sm.balance_after FROM stock_movements sm WHERE sm.product_id = products.id ORDER BY sm.occurred_at DESC, sm.id DESC LIMIT 1) AS last_balance')
            ->get();

        $problems = [];

        foreach ($rows as $row) {
            if ((int) $row->stock_on_hand !== (int) $row->last_balance) {
                $problems[] = "5. {$row->name} has {$row->stock_on_hand} on hand but its last movement left {$row->last_balance}.";
            }
        }

        return $problems;
    }

    /**
     * 6. For every closed shift: the totals recomputed from the ledger equal the frozen
     * `totals`, and the three roll-forwards balance. A closed row with no totals (null or an
     * empty array: a factory placeholder in a test) has nothing to compare and is skipped.
     *
     * @return list<string>
     */
    public static function closedShiftTotalsHold(): array
    {
        $reports = app(ReportService::class);
        $cash = app(CashMath::class);
        $problems = [];

        $shifts = ShiftSession::query()->withoutGlobalScopes()
            ->where('status', ShiftStatus::Closed->value)
            ->whereNotNull('totals')
            ->orderBy('id')
            ->get();

        foreach ($shifts as $shift) {
            $label = "{$shift->period_name} {$shift->business_date->toDateString()} (shift {$shift->id})";
            $stored = $shift->totals ?? [];

            if ($stored === []) {
                continue;
            }
            $again = $reports->recompute($shift);

            // Figures only: a renamed person or discount is not a ledger change.
            if ($reports->totalsDiff($stored, $again) !== []) {
                $problems[] = "6. {$label}: the totals recomputed from the ledger differ from the frozen totals.";
            }

            foreach ($cash->rollForwardsBalance($stored) as $name => $balances) {
                if (! $balances) {
                    $problems[] = "6. {$label}: the {$name} roll-forward does not balance.";
                }
            }
        }

        return $problems;
    }

    /**
     * 7. For every business day: the sum of its closed shifts' totals equals the day read
     * straight from the ledger (net sales, collected, cash in). Shift-level differences are
     * invariant 6; this adds the day as a whole.
     *
     * @return list<string>
     */
    public static function businessDaysAgree(): array
    {
        $reports = app(ReportService::class);
        $problems = [];
        $days = DB::table('shift_sessions')->where('status', ShiftStatus::Closed->value)->whereNotNull('totals')
            ->select('branch_id', 'business_date')->distinct()->get();

        foreach ($days as $day) {
            $date = substr((string) $day->business_date, 0, 10);

            foreach ($reports->reconcileDay((int) $day->branch_id, $date, shiftLevel: false) as $difference) {
                if ($difference['shift_id'] === null) {
                    $problems[] = "7. Branch {$day->branch_id}, {$date}: {$difference['figure']} is {$difference['stored']} in the shifts' totals but {$difference['recomputed']} in the ledger.";
                }
            }
        }

        return $problems;
    }

    /**
     * 8. Shift N's forwarded-out rows are shift N+1's forwarded-in: the same stays and amounts,
     * as frozen in both shifts' totals.
     *
     * @return list<string>
     */
    public static function forwardedChainHolds(): array
    {
        $problems = [];
        $handovers = DB::table('shift_handovers')->whereNotNull('to_shift_id')->get(['from_shift_id', 'to_shift_id']);

        foreach ($handovers as $handover) {
            $from = ShiftSession::query()->withoutGlobalScopes()->find((int) $handover->from_shift_id);
            $to = ShiftSession::query()->withoutGlobalScopes()->find((int) $handover->to_shift_id);
            $rows = ShiftForwardedStay::query()->where('shift_session_id', (int) $handover->from_shift_id)->get();
            $count = $rows->count();
            $unpaid = (int) $rows->sum(fn (ShiftForwardedStay $r): int => $r->unpaid_amount?->centavos() ?? 0);
            $key = (int) $rows->sum(fn (ShiftForwardedStay $r): int => $r->key_deposit_held?->centavos() ?? 0);

            if ($from !== null && ($from->totals ?? []) !== [] && (self::figure($from->totals ?? [], 'guests', 'forwarded_out') !== $count || self::figure($from->totals ?? [], 'unpaid', 'forwarded_out') !== $unpaid)) {
                $problems[] = "8. Shift {$from->id}'s forwarded-out figures differ from its forwarded rows.";
            }

            if ($to !== null && ($to->totals ?? []) !== [] && (self::figure($to->totals ?? [], 'guests', 'forwarded_in') !== $count
                || self::figure($to->totals ?? [], 'unpaid', 'forwarded_in') !== $unpaid
                || self::figure($to->totals ?? [], 'deposits', 'held_open') !== $key)) {
                $problems[] = "8. Shift {$to->id}'s forwarded-in figures differ from shift {$handover->from_shift_id}'s forwarded-out rows.";
            }
        }

        return $problems;
    }

    /** @param  array<array-key, mixed>  $totals */
    private static function figure(array $totals, string $section, string $key): ?int
    {
        $part = $totals[$section] ?? null;
        $value = is_array($part) ? ($part[$key] ?? null) : null;

        return is_int($value) ? $value : null;
    }
}
