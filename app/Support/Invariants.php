<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\RoomStatus;
use App\Enums\ShiftStatus;
use App\Enums\StayState;
use Illuminate\Support\Facades\DB;

/**
 * The data invariants of TRD §8, items 1–5. Each check returns one sentence per broken row;
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
}
