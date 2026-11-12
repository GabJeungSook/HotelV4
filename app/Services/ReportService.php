<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ApprovalAction;
use App\Enums\CashMovementKind;
use App\Enums\DepositType;
use App\Enums\DiscountKind;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PrintDocument;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Discount;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PosOrder;
use App\Models\PrintLog;
use App\Models\ShiftForwardedStay;
use App\Models\ShiftHandover;
use App\Models\ShiftMember;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Queries\ReportQuery;
use App\Queries\StayQuery;
use App\Services\Reports\BusinessDay;
use App\Services\Reports\DayReaders;
use App\Services\Reports\ShiftReaders;
use App\Services\Reports\ZTabs;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Report figures read from the ledger (TRD §4.10, SYSTEM_DESIGN §5.2). computeShiftTotals()
 * makes the frozen `totals` JSON of a shift (DATABASE_SCHEMA §4.7) in the closing transaction.
 * The readers build every report from those totals: a closed shift is never recomputed for a
 * report (only the reconcile check and invariant 6 recompute it, to prove nothing changed).
 *
 * - shift readers (Reports\ShiftReaders): figures, zReport (37b), slip (26), shift (37), dayZ (38b)
 * - Z-report tabs (Reports\ZTabs): zRooms (37c), zCleaning, zExpenses
 * - day readers (Reports\DayReaders): daily (38), reconcileDay, summary (39), occupancy (40),
 *   housekeeping (41), inventory (42), audit (43)
 */
final class ReportService
{
    use DayReaders;
    use ShiftReaders;
    use ZTabs;

    public function __construct(
        private readonly Clock $clock,
        private readonly StayQuery $stays,
        private readonly CashMath $cash,
        private readonly BusinessDay $days,
        private readonly ReportQuery $queries,
    ) {}

    /**
     * A closed shift recomputed from the ledger the way it was frozen (same forwarded rows, same
     * VAT rate), for the reconcile check and invariant 6. Reports never call this.
     *
     * @return array<string, mixed>
     */
    public function recompute(ShiftSession $shift): array
    {
        $frozen = is_array($shift->totals['vat'] ?? null) ? (int) ($shift->totals['vat']['rate'] ?? 0) : false;

        return $this->computeShiftTotals($shift, $this->forwardedIn($shift), ShiftForwardedStay::query()->where('shift_session_id', $shift->id)->orderBy('id')->get(), vatRate: $frozen);
    }

    /**
     * The rows the shift before this one forwarded out: this shift's "forwarded in". Found
     * through the handover that opened this shift (or that it linked at Open shift).
     *
     * @return Collection<int, ShiftForwardedStay>
     */
    public function forwardedIn(ShiftSession $shift): Collection
    {
        $from = ShiftHandover::query()->where('to_shift_id', $shift->id)->value('from_shift_id');

        return $from === null
            ? new Collection
            : ShiftForwardedStay::query()->where('shift_session_id', $from)->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, ShiftForwardedStay>  $forwardedIn  rows the previous shift forwarded out
     * @param  Collection<int, ShiftForwardedStay>  $forwardedOut  rows this shift forwards out
     * @param  int|false|null  $vatRate  null: the branch's VAT setting now (at the close); false: no
     *                                   VAT; a rate: that rate. A recompute passes what was frozen,
     *                                   so switching VAT on or off later does not look like a change
     *                                   to an old shift.
     * @return array<string, mixed>
     */
    public function computeShiftTotals(ShiftSession $shift, Collection $forwardedIn, Collection $forwardedOut, ?CarbonImmutable $until = null, int|false|null $vatRate = null): array
    {
        $lines = FolioLine::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->get();
        $payments = Payment::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->get();
        $allocations = PaymentAllocation::query()->whereIn('payment_id', $payments->pluck('id'))->get();
        $movements = CashMovement::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->get();

        $sum = fn (Collection $rows, string $column = 'amount'): int => (int) $rows->sum(fn ($row): int => $row->{$column}->centavos());
        $ofKind = fn (LineKind $kind): Collection => $lines->where('kind', $kind);

        $sales = [];
        foreach (['room', 'extension', 'transfer_difference', 'food_drink', 'amenity', 'damage', 'forfeited_deposit', 'discount'] as $kind) {
            $sales[$kind] = $sum($ofKind(LineKind::from($kind)));
        }
        $sales['total'] = $sum($lines);

        $counts = [];
        foreach (['room', 'extension', 'transfer_difference', 'food_drink', 'amenity', 'damage'] as $kind) {
            $counts[$kind] = $ofKind(LineKind::from($kind))->whereNull('reversal_of_id')->reject(fn (FolioLine $l): bool => $lines->contains('reversal_of_id', $l->id))->count();
        }

        $paymentIds = fn (PaymentKind $kind, ?PaymentMethod $method = null): Collection => $payments
            ->where('kind', $kind)->when($method !== null, fn (Collection $c) => $c->where('method', $method))->pluck('id');
        $allocated = fn (Collection $ids): int => $sum($allocations->whereIn('payment_id', $ids));

        $collected = [];
        foreach ([PaymentMethod::Cash, PaymentMethod::Gcash, PaymentMethod::Card] as $method) {
            $collected[$method->value] = $allocated($paymentIds(PaymentKind::Payment, $method));
        }
        $collected['deposit'] = $allocated($paymentIds(PaymentKind::DepositApplied));

        $key = $payments->where('deposit_type', DepositType::Key);
        // An unclaimed key deposit is kept with a forfeited_deposit line paid by a deposit_applied
        // payment: it counts once, as forfeited, so the deposits roll-forward balances (TRD §4.5).
        $forfeitLineIds = $ofKind(LineKind::ForfeitedDeposit)->pluck('id');
        $keyForfeited = $sum($allocations
            ->whereIn('payment_id', $key->where('kind', PaymentKind::DepositApplied)->pluck('id'))
            ->whereIn('folio_line_id', $forfeitLineIds));
        $deposits = [
            'held_open' => $sum($forwardedIn, 'key_deposit_held'),
            'taken' => $sum($key->where('kind', PaymentKind::DepositIn)),
            'returned' => -$sum($key->where('kind', PaymentKind::DepositRefund)),
            'forfeited' => $keyForfeited,
            'applied' => $sum($key->where('kind', PaymentKind::DepositApplied)) - $keyForfeited,
            'held_close' => $sum($forwardedOut, 'key_deposit_held'),
        ];

        $givenBack = fn (Collection $rows): Collection => $rows->filter(fn (Payment $p): bool => $p->kind === PaymentKind::Refund
            || ($p->kind === PaymentKind::DepositRefund && $p->deposit_type === DepositType::Guest));

        $byMethod = [];
        foreach (PaymentMethod::cases() as $method) {
            $ofMethod = $payments->where('method', $method);
            $kind = $method === PaymentMethod::Deposit ? PaymentKind::DepositApplied : PaymentKind::Payment;
            $byMethod[$method->value] = [
                'count' => $ofMethod->where('kind', $kind)->count(),
                'amount' => $allocated($ofMethod->where('kind', $kind)->pluck('id')),
                'refunds' => -$sum($givenBack($ofMethod)),
            ];
        }

        $end = $until ?? $shift->closed_at ?? $this->clock->now();
        $window = fn ($query) => $query->whereBetween('created_at', [$shift->opened_at, $end]);
        $cancelApprovals = $window(Approval::query()->withoutGlobalScopes()->where('branch_id', $shift->branch_id)
            ->whereIn('action', [ApprovalAction::CancelStay, ApprovalAction::CancelReservation]))->get();
        $refunds = $givenBack($payments);
        $cancelledInHouse = Stay::query()->withoutGlobalScopes()
            ->whereIn('id', $cancelApprovals->where('action', ApprovalAction::CancelStay)->pluck('subject_id'))
            ->whereNotNull('checked_in_at')
            ->count();
        $voided = $movements->whereNotNull('voided_at');
        $live = $movements->whereNull('voided_at');

        $stays = Stay::query()->withoutGlobalScopes()->where('checkin_shift_id', $shift->id)->orderBy('code')->get();
        $orders = PosOrder::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->orderBy('order_no')->get();

        $byUser = ShiftMember::query()->with('user')->where('shift_session_id', $shift->id)->orderBy('joined_at')->get()
            ->map(function (ShiftMember $member) use ($payments, $stays, $allocated, $sum, $givenBack): array {
                $own = $payments->where('created_by', $member->user_id);
                $paid = $own->where('kind', PaymentKind::Payment);

                return [
                    'user_id' => $member->user_id,
                    'name' => $member->user->name,
                    'checkins' => $stays->where('checked_in_by', $member->user_id)->count(),
                    'cash' => $allocated($paid->where('method', PaymentMethod::Cash)->pluck('id')),
                    'non_cash' => $allocated($paid->where('method', '!=', PaymentMethod::Cash)->pluck('id')),
                    'given_back' => -$sum($givenBack($own)),
                ];
            })->values()->all();

        // A discount and its reversal (a cancelled stay) cancel out: the pair is not counted.
        $reversedIds = $lines->whereNotNull('reversal_of_id')->pluck('reversal_of_id');
        $discountLabels = $this->discountLabels($shift->branch_id);
        $discounts = $ofKind(LineKind::Discount)->groupBy('description')
            ->map(fn (Collection $rows, string $name): array => [
                'name' => $name,
                'label' => $discountLabels[$name] ?? $name,
                'count' => $rows->whereNull('reversal_of_id')->reject(fn (FolioLine $l): bool => $reversedIds->contains($l->id))->count(),
                'amount' => -$sum($rows),
            ])
            ->sortBy('name')->values()->all();

        $drawer = $this->drawer($payments, $allocations, $live, $forwardedIn, $forwardedOut);

        return [
            'sales' => $sales,
            'sales_counts' => $counts,
            'collected' => $collected,
            'deposits' => $deposits,
            'unpaid' => [
                'forwarded_in' => $sum($forwardedIn, 'unpaid_amount'),
                'charged' => $sales['total'],
                'collected' => $sum($allocations),
                'forwarded_out' => $sum($forwardedOut, 'unpaid_amount'),
            ],
            'guests' => [
                'forwarded_in' => $forwardedIn->count(),
                'checked_in' => $stays->count(),
                // A guest whose stay is cancelled during the shift leaves the house as a check-out
                // does, so the guests roll-forward balances; `cancelled` says how many of them.
                'checked_out' => Stay::query()->withoutGlobalScopes()->where('checkout_shift_id', $shift->id)->count() + $cancelledInHouse,
                'forwarded_out' => $forwardedOut->count(),
                'cancelled' => $cancelledInHouse,
            ],
            'cash' => [
                'opening' => $shift->opening_cash?->centavos() ?? 0,
                'expenses' => $sum($live->where('kind', CashMovementKind::Expense)),
                'remittance' => $sum($live->where('kind', CashMovementKind::Remittance)),
                'shortage_payments' => $sum($live->where('kind', CashMovementKind::ShortagePayment)),
            ],
            'gross_sales' => $sales['total'] - $sales['discount'],
            'discounts' => $discounts,
            'payments_by_method' => $byMethod,
            'exceptions' => [
                'cancelled_stays' => ['count' => $cancelApprovals->count(), 'amount' => -$sum($refunds->whereIn('stay_id', $cancelApprovals->pluck('subject_id')))],
                'voided_lines' => ['count' => $lines->whereNotNull('reversal_of_id')->count(), 'amount' => -$sum($lines->whereNotNull('reversal_of_id'))],
                'cash_refunds' => ['count' => $refunds->where('method', PaymentMethod::Cash)->count(), 'amount' => -$sum($refunds->where('method', PaymentMethod::Cash))],
                'transfers' => ['count' => $window(Approval::query()->withoutGlobalScopes()->where('branch_id', $shift->branch_id)->where('action', ApprovalAction::Transfer))->count(), 'amount' => $sales['transfer_difference']],
                'forfeited_deposits' => ['count' => $ofKind(LineKind::ForfeitedDeposit)->count(), 'amount' => $sales['forfeited_deposit']],
                'voided_cash_movements' => ['count' => $voided->count(), 'amount' => $sum($voided)],
                // Slips only (8, 18, 26): exporting or reprinting a report is logged but is not a slip reprint.
                'reprints' => ['count' => PrintLog::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->where('is_reprint', true)
                    ->whereIn('document', [PrintDocument::CheckinSlip, PrintDocument::Receipt, PrintDocument::ShiftSlip])->count()],
                'force_closed' => $shift->is_force_closed,
            ],
            'by_user' => $byUser,
            'documents' => [
                'stays' => ['first' => $stays->first()?->code, 'last' => $stays->last()?->code, 'count' => $stays->count()],
                'pos_orders' => ['first' => $orders->first()?->order_no, 'last' => $orders->last()?->order_no, 'count' => $orders->count()],
            ],
            'drawer' => $drawer,
            'vat' => $this->vat($shift->branch_id, $lines, $vatRate),
        ];
    }

    /**
     * What went in and out of the drawer, for the "Sales and cash" column of 37b (TRD §4.10).
     * Every cash payment row lands in exactly one line, so the column walks to CashMath's
     * expected cash: cash collected (cash applied to bills, refunds included) + key deposits
     * taken − returned + guest deposits (net) + any other cash − expenses − remittance +
     * shortage payments + opening = opening + Σ cash payments − expenses − remittance +
     * shortage payments.
     *
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, PaymentAllocation>  $allocations
     * @param  Collection<int, CashMovement>  $live  cash movements of the shift that are not voided
     * @param  Collection<int, ShiftForwardedStay>  $forwardedIn
     * @param  Collection<int, ShiftForwardedStay>  $forwardedOut
     * @return array<string, mixed>
     */
    private function drawer(Collection $payments, Collection $allocations, Collection $live, Collection $forwardedIn, Collection $forwardedOut): array
    {
        $amount = fn (Collection $rows): int => (int) $rows->sum(fn (Payment $p): int => $p->amount?->centavos() ?? 0);
        $applied = fn (Collection $rows): int => (int) $allocations->whereIn('payment_id', $rows->pluck('id'))->sum(fn (PaymentAllocation $a): int => $a->amount->centavos());
        $billKinds = [PaymentKind::Payment, PaymentKind::Refund, PaymentKind::DepositApplied];

        $nonCash = [];
        $nonCashCounts = [];
        foreach ([PaymentMethod::Gcash, PaymentMethod::Card, PaymentMethod::Deposit] as $method) {
            $rows = $payments->where('method', $method)->whereIn('kind', $billKinds);
            $nonCash[$method->value] = $applied($rows);
            $nonCashCounts[$method->value] = $rows->whereIn('kind', [PaymentKind::Payment, PaymentKind::DepositApplied])->count();
        }

        $cash = $payments->where('method', PaymentMethod::Cash);
        $cashBills = $cash->whereIn('kind', $billKinds);
        $keyIn = $cash->where('kind', PaymentKind::DepositIn)->where('deposit_type', DepositType::Key);
        $keyOut = $cash->where('kind', PaymentKind::DepositRefund)->where('deposit_type', DepositType::Key);
        $guest = $cash->whereIn('kind', [PaymentKind::DepositIn, PaymentKind::DepositRefund])->where('deposit_type', DepositType::Guest);
        $cashApplied = $applied($cashBills);
        $other = $amount($cash) - $cashApplied - $amount($keyIn) - $amount($keyOut) - $amount($guest);
        $count = fn (CashMovementKind $kind): int => $live->where('kind', $kind)->count();
        $unpaid = fn (Collection $rows): int => $rows->filter(fn (ShiftForwardedStay $f): bool => ($f->unpaid_amount?->centavos() ?? 0) > 0)->count();

        return [
            'cash_collected' => $cashApplied,
            'non_cash' => $nonCash,
            'non_cash_counts' => $nonCashCounts,
            'deposits_taken' => ['count' => $keyIn->count(), 'amount' => $amount($keyIn)],
            'deposits_returned' => ['count' => $keyOut->count(), 'amount' => -$amount($keyOut)],
            'guest_deposits' => ['count' => $guest->count(), 'amount' => $amount($guest)],
            'other_cash' => $other,
            'cash_in' => $amount($cash),
            'counts' => [
                'expenses' => $count(CashMovementKind::Expense),
                'remittance' => $count(CashMovementKind::Remittance),
                'shortage_payments' => $count(CashMovementKind::ShortagePayment),
                'unpaid_forwarded_out' => $unpaid($forwardedOut),
                'unpaid_forwarded_in' => $unpaid($forwardedIn),
            ],
        ];
    }

    /**
     * "Promo ₱50", "Senior citizen 20%": the name with its value, frozen with the shift so a
     * later change of the discount does not change an old paper.
     *
     * @return array<string, string>
     */
    private function discountLabels(int $branchId): array
    {
        return Discount::query()->withoutGlobalScopes()->where('branch_id', $branchId)->get()
            ->mapWithKeys(fn (Discount $d): array => [$d->name => $d->name.' '.($d->kind === DiscountKind::Percent
                ? $d->value.'%'
                : '₱'.self::pesos($d->value))])
            ->all();
    }

    /** 5000 → "50", 5050 → "50.50": a discount value without trailing zero centavos. */
    private static function pesos(int $centavos): string
    {
        return $centavos % 100 === 0
            ? number_format(intdiv($centavos, 100))
            : number_format(intdiv($centavos, 100)).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * The VAT block (TRD §4.10), only when the branch has VAT switched on; otherwise null and
     * nothing here runs. Prices are VAT-inclusive. Per line: vatable = amount / (1 + rate),
     * rounded half-up to the centavo, vat = amount − vatable; the room and discount lines of a
     * stay whose discount is VAT-exempt (senior citizen, PWD) are reported as exempt.
     *
     * @param  Collection<int, FolioLine>  $lines
     * @return array{rate: int, vatable: int, vat: int, exempt: int}|null
     */
    private function vat(int $branchId, Collection $lines, int|false|null $rate): ?array
    {
        if ($rate === null) {
            $branch = Branch::query()->withoutGlobalScopes()->find($branchId);
            $rate = $branch === null || ! $branch->vat_enabled ? false : (int) $branch->vat_rate_percent;
        }

        if ($rate === false) {
            return null;
        }

        $exemptStays = Stay::query()->withoutGlobalScopes()
            ->whereIn('id', $lines->pluck('stay_id')->filter()->unique()->values())
            ->whereHas('discount', fn ($q) => $q->withoutGlobalScopes()->where('is_vat_exempt', true))
            ->pluck('id')->all();

        $vatable = 0;
        $vat = 0;
        $exempt = 0;

        foreach ($lines as $line) {
            $amount = $line->amount->centavos();

            if (in_array($line->stay_id, $exemptStays, true) && in_array($line->kind, [LineKind::Room, LineKind::Discount], true)) {
                $exempt += $amount;

                continue;
            }

            $net = self::divideHalfUp($amount * 100, 100 + $rate);
            $vatable += $net;
            $vat += $amount - $net;
        }

        return ['rate' => $rate, 'vatable' => $vatable, 'vat' => $vat, 'exempt' => $exempt];
    }

    /** a / b rounded half away from zero, in integers (no floats for money). */
    private static function divideHalfUp(int $a, int $b): int
    {
        $sign = $a < 0 ? -1 : 1;

        return $sign * intdiv(2 * abs($a) + $b, 2 * $b);
    }
}
