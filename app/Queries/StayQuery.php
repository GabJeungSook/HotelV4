<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\StayState;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Stay;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read side of a stay's folio (DATABASE_SCHEMA §4.12, §7). Balance and deposits are always
 * derived from the ledgers, never stored:
 *
 * - balance due = Σ folio_lines.amount − Σ payment_allocations.amount over the stay's lines
 * - deposit held (per type) = Σ deposit_in + Σ deposit_refund − Σ deposit_applied
 */
final class StayQuery
{
    public function balanceDue(int $stayId): Money
    {
        $lines = (int) DB::table('folio_lines')->where('stay_id', $stayId)->sum('amount');
        $paid = (int) DB::table('payment_allocations')
            ->join('folio_lines', 'folio_lines.id', '=', 'payment_allocations.folio_line_id')
            ->where('folio_lines.stay_id', $stayId)
            ->sum('payment_allocations.amount');

        return Money::ofCentavos($lines - $paid);
    }

    public function depositHeld(int $stayId, DepositType $type): Money
    {
        $held = DB::table('payments')
            ->where('stay_id', $stayId)
            ->where('deposit_type', $type->value)
            ->selectRaw('COALESCE(SUM(CASE WHEN kind = ? THEN -amount ELSE amount END), 0) AS held', [PaymentKind::DepositApplied->value])
            ->value('held');

        return Money::ofCentavos((int) $held);
    }

    /**
     * Lines of a stay that are not fully settled, oldest first, with what is still due on each
     * (negative for a credit such as an unpaid discount or reversal line).
     *
     * @return list<array{line: FolioLine, due: Money}>
     */
    public function openLines(int $stayId): array
    {
        $lines = FolioLine::query()->withoutGlobalScopes()
            ->where('stay_id', $stayId)
            ->withSum('allocations', 'amount')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $open = [];

        foreach ($lines as $line) {
            $due = ($line->amount ?? Money::zero())->minus(Money::ofCentavos((int) $line->getAttribute('allocations_sum_amount')));

            if (! $due->isZero()) {
                $open[] = ['line' => $line, 'due' => $due];
            }
        }

        return $open;
    }

    /**
     * Everything the folio (9) shows: the stay, its lines with what was paid on each and their
     * status, its payments, the deposits held and the balance.
     *
     * @return array{
     *     stay: Stay,
     *     lines: list<array{id: int, kind: LineKind, description: string, quantity: int, unit_amount: Money, amount: Money, paid: Money, status: string, reversal_of_id: int|null, occurred_at: CarbonImmutable|null, created_by: int}>,
     *     payments: list<Payment>,
     *     deposits: array{key: Money, guest: Money},
     *     balance_due: Money,
     * }
     */
    public function folio(int $stayId): array
    {
        $stay = Stay::query()->withoutGlobalScopes()->with('room')->findOrFail($stayId);

        $lines = FolioLine::query()->withoutGlobalScopes()
            ->where('stay_id', $stayId)
            ->withSum('allocations', 'amount')
            ->withExists('reversal')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($lines as $line) {
            $amount = $line->amount ?? Money::zero();
            $paid = Money::ofCentavos((int) $line->getAttribute('allocations_sum_amount'));
            $status = match (true) {
                (bool) $line->getAttribute('reversal_exists') || $line->reversal_of_id !== null => 'reversed',
                $paid->equals($amount) => 'paid',
                default => 'unpaid',
            };

            $rows[] = [
                'id' => $line->id,
                'kind' => $line->kind,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_amount' => $line->unit_amount ?? Money::zero(),
                'amount' => $amount,
                'paid' => $paid,
                'status' => $status,
                'reversal_of_id' => $line->reversal_of_id,
                'occurred_at' => $line->occurred_at,
                'created_by' => $line->created_by,
            ];
        }

        $payments = Payment::query()->withoutGlobalScopes()->where('stay_id', $stayId)->orderBy('occurred_at')->orderBy('id')->get()->all();

        return [
            'stay' => $stay,
            'lines' => $rows,
            'payments' => array_values($payments),
            'deposits' => [
                'key' => $this->depositHeld($stayId, DepositType::Key),
                'guest' => $this->depositHeld($stayId, DepositType::Guest),
            ],
            'balance_due' => $this->balanceDue($stayId),
        ];
    }

    /**
     * Stays in house (checked in) with what is unpaid and the deposits held (22, 25). Four
     * queries whatever the number of stays.
     *
     * @return list<array{stay_id: int, code: string, room_id: int, room_number: string, guest_name: string, expected_checkout_at: CarbonImmutable|null, unpaid: Money, key_deposit_held: Money, guest_deposit_held: Money}>
     */
    public function inHouse(int $branchId): array
    {
        $stays = Stay::query()->withoutGlobalScopes()
            ->where('stays.branch_id', $branchId)
            ->where('state', StayState::CheckedIn)
            ->join('rooms', 'rooms.id', '=', 'stays.room_id')
            ->orderBy('rooms.number')
            ->get(['stays.*', 'rooms.number as room_number']);

        $ids = $stays->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $charged = DB::table('folio_lines')->whereIn('stay_id', $ids)->groupBy('stay_id')->selectRaw('stay_id, SUM(amount) AS total')->pluck('total', 'stay_id');
        $paid = DB::table('payment_allocations')
            ->join('folio_lines', 'folio_lines.id', '=', 'payment_allocations.folio_line_id')
            ->whereIn('folio_lines.stay_id', $ids)
            ->groupBy('folio_lines.stay_id')
            ->selectRaw('folio_lines.stay_id AS stay_id, SUM(payment_allocations.amount) AS total')
            ->pluck('total', 'stay_id');
        $deposits = DB::table('payments')
            ->whereIn('stay_id', $ids)
            ->whereNotNull('deposit_type')
            ->groupBy('stay_id', 'deposit_type')
            ->selectRaw('stay_id, deposit_type, SUM(CASE WHEN kind = ? THEN -amount ELSE amount END) AS held', [PaymentKind::DepositApplied->value])
            ->get();

        $held = [];
        foreach ($deposits as $row) {
            $held[(int) $row->stay_id][(string) $row->deposit_type] = (int) $row->held;
        }

        $rows = [];

        foreach ($stays as $stay) {
            $rows[] = [
                'stay_id' => $stay->id,
                'code' => $stay->code,
                'room_id' => (int) $stay->room_id,
                'room_number' => (string) $stay->getAttribute('room_number'),
                'guest_name' => $stay->guest_name,
                'expected_checkout_at' => $stay->expected_checkout_at,
                'unpaid' => Money::ofCentavos((int) ($charged[$stay->id] ?? 0) - (int) ($paid[$stay->id] ?? 0)),
                'key_deposit_held' => Money::ofCentavos($held[$stay->id][DepositType::Key->value] ?? 0),
                'guest_deposit_held' => Money::ofCentavos($held[$stay->id][DepositType::Guest->value] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Kiosk holds of the branch (5, DATABASE_SCHEMA §7): the live ones by hold_expires_at, then
     * the ones that expired in the last hour, newest first, as "Expired 9:41 AM".
     *
     * @return list<Stay>
     */
    public function kioskHolds(int $branchId, CarbonImmutable $now): array
    {
        $live = Stay::query()->withoutGlobalScopes()
            ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'roomType' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('branch_id', $branchId)
            ->where('state', StayState::Held)
            ->orderBy('hold_expires_at')
            ->get();

        $expired = Stay::query()->withoutGlobalScopes()
            ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'roomType' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('branch_id', $branchId)
            ->where('state', StayState::Expired)
            ->where('hold_expires_at', '>', $now->subHour())
            ->orderByDesc('hold_expires_at')
            ->get();

        return [...$live->all(), ...$expired->all()];
    }

    /**
     * Checked-in stays that have requested checkout at the kiosk, oldest request first — the
     * front desk's realtime checkout-requests sheet.
     *
     * @return list<Stay>
     */
    public function checkoutRequests(int $branchId): array
    {
        return Stay::query()->withoutGlobalScopes()
            ->with(['room' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('branch_id', $branchId)
            ->where('state', StayState::CheckedIn)
            ->whereNotNull('checkout_requested_at')
            ->orderBy('checkout_requested_at')
            ->get()
            ->all();
    }

    /**
     * Reservations of the branch by arrival (57, DATABASE_SCHEMA §7), with the room and type.
     *
     * @param  list<StayState>  $states
     * @return list<Stay>
     */
    public function reservations(int $branchId, CarbonImmutable $from, CarbonImmutable $to, array $states, bool $newestFirst = false): array
    {
        return Stay::query()->withoutGlobalScopes()
            ->with(['room' => fn ($q) => $q->withoutGlobalScopes(), 'roomType' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('branch_id', $branchId)
            ->where('source', 'reservation')
            ->whereIn('state', $states)
            ->where('arrival_at', '>=', $from)
            ->where('arrival_at', '<', $to)
            ->orderBy('arrival_at', $newestFirst ? 'desc' : 'asc')
            ->get()
            ->all();
    }

    /**
     * Money paid ahead per stay: every guest deposit_in on it (what was paid, not what is left).
     *
     * @param  list<int>  $stayIds
     * @return array<int, int>
     */
    public function paidAhead(array $stayIds): array
    {
        if ($stayIds === []) {
            return [];
        }

        return DB::table('payments')
            ->whereIn('stay_id', $stayIds)
            ->where('kind', PaymentKind::DepositIn->value)
            ->where('deposit_type', DepositType::Guest->value)
            ->groupBy('stay_id')
            ->selectRaw('stay_id, SUM(amount) AS paid')
            ->pluck('paid', 'stay_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }
}
