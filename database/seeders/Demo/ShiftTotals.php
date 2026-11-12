<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\ApprovalAction;
use App\Enums\CashMovementKind;
use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Approval;
use App\Models\CashMovement;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PosOrder;
use App\Models\PrintLog;
use App\Models\ShiftForwardedStay;
use App\Models\ShiftMember;
use App\Models\ShiftSession;
use App\Models\Stay;
use Illuminate\Support\Collection;

/**
 * Builds the frozen `totals` JSON (DATABASE_SCHEMA §4.7) for the demo shifts from
 * their ledger rows. A seeding stand-in only: the real builder is
 * ReportService::computeShiftTotals() in Phase 8, which must give the same figures
 * (tests/Feature/Database/DemoShiftSeederTest.php pins them to the mockup).
 */
final class ShiftTotals
{
    /**
     * @param  Collection<int, ShiftForwardedStay>  $forwardedIn  rows the previous shift forwarded out
     * @param  Collection<int, ShiftForwardedStay>  $forwardedOut  rows this shift forwards out
     * @return array<string, mixed>
     */
    public static function build(ShiftSession $shift, Collection $forwardedIn, Collection $forwardedOut): array
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
        $deposits = [
            'held_open' => $sum($forwardedIn, 'key_deposit_held'),
            'taken' => $sum($key->where('kind', PaymentKind::DepositIn)),
            'returned' => -$sum($key->where('kind', PaymentKind::DepositRefund)),
            'forfeited' => $sum($ofKind(LineKind::ForfeitedDeposit)),
            'applied' => $sum($key->where('kind', PaymentKind::DepositApplied)),
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

        $window = fn ($query) => $query->whereBetween('created_at', [$shift->opened_at, $shift->closed_at]);
        $cancelApprovals = $window(Approval::query()->withoutGlobalScopes()->where('branch_id', $shift->branch_id)
            ->whereIn('action', [ApprovalAction::CancelStay, ApprovalAction::CancelReservation]))->get();
        $refunds = $givenBack($payments);
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

        $discounts = $ofKind(LineKind::Discount)->groupBy('description')
            ->map(fn (Collection $rows, string $name): array => ['name' => $name, 'count' => $rows->count(), 'amount' => -$sum($rows)])
            ->sortBy('name')->values()->all();

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
                'checked_out' => Stay::query()->withoutGlobalScopes()->where('checkout_shift_id', $shift->id)->count(),
                'forwarded_out' => $forwardedOut->count(),
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
                'reprints' => ['count' => PrintLog::query()->withoutGlobalScopes()->where('shift_session_id', $shift->id)->where('is_reprint', true)->count()],
                'force_closed' => $shift->is_force_closed,
            ],
            'by_user' => $byUser,
            'documents' => [
                'stays' => ['first' => $stays->first()?->code, 'last' => $stays->last()?->code, 'count' => $stays->count()],
                'pos_orders' => ['first' => $orders->first()?->order_no, 'last' => $orders->last()?->order_no, 'count' => $orders->count()],
            ],
            'vat' => null,
        ];
    }
}
