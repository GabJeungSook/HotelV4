<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CashMovementKind;
use App\Enums\PaymentMethod;
use App\Models\CashMovement;
use App\Models\Payment;
use App\Support\Money;

/**
 * Drawer maths and the roll-forwards printed on every shift report (TRD §4.5). Pure:
 * values in, values out. Rows passed in are read as values; nothing is queried here.
 */
final class CashMath
{
    /**
     * expected = opening + cash payments − expenses − remittances + shortage payments.
     * Cash payments are signed (refunds and deposit returns are negative).
     */
    public function expectedCash(Money $opening, Money $cashPayments, Money $expenses, Money $remittances, Money $shortagePayments): Money
    {
        return $opening->plus($cashPayments)->minus($expenses)->minus($remittances)->plus($shortagePayments);
    }

    /**
     * The same formula from a shift's rows: payments with method cash, and cash movements
     * that are not voided.
     *
     * @param  iterable<Payment>  $payments
     * @param  iterable<CashMovement>  $movements
     */
    public function expectedFromRows(Money $opening, iterable $payments, iterable $movements): Money
    {
        $cash = Money::zero();

        foreach ($payments as $payment) {
            if ($payment->method === PaymentMethod::Cash) {
                $cash = $cash->plus($payment->amount ?? Money::zero());
            }
        }

        $out = ['expense' => Money::zero(), 'remittance' => Money::zero(), 'shortage_payment' => Money::zero()];

        foreach ($movements as $movement) {
            if ($movement->voided_at !== null) {
                continue;
            }

            $kind = $movement->kind->value;
            $out[$kind] = $out[$kind]->plus($movement->amount ?? Money::zero());
        }

        return $this->expectedCash($opening, $cash, $out[CashMovementKind::Expense->value], $out[CashMovementKind::Remittance->value], $out[CashMovementKind::ShortagePayment->value]);
    }

    /** counted − expected; negative means short. */
    public function variance(Money $counted, Money $expected): Money
    {
        return $counted->minus($expected);
    }

    /** guests: forwarded_in + checked_in − checked_out = forwarded_out */
    public function guestsForwardedOut(int $forwardedIn, int $checkedIn, int $checkedOut): int
    {
        return $forwardedIn + $checkedIn - $checkedOut;
    }

    /** deposits: held_open + taken − returned − forfeited − applied = held_close */
    public function depositsHeldClose(Money $heldOpen, Money $taken, Money $returned, Money $forfeited, Money $applied): Money
    {
        return $heldOpen->plus($taken)->minus($returned)->minus($forfeited)->minus($applied);
    }

    /** unpaid: forwarded_in + charged − collected = forwarded_out (collected = allocations made in the shift) */
    public function unpaidForwardedOut(Money $forwardedIn, Money $charged, Money $collected): Money
    {
        return $forwardedIn->plus($charged)->minus($collected);
    }

    /**
     * Whether the three roll-forwards of a shift's frozen totals balance (TRD §4.5, invariant 6).
     *
     * @param  array<string, mixed>  $totals  the shift_sessions.totals shape (DATABASE_SCHEMA §4.7)
     * @return array{guests: bool, deposits: bool, unpaid: bool}
     */
    public function rollForwardsBalance(array $totals): array
    {
        $int = static function (mixed $section, string $key): int {
            $value = is_array($section) ? ($section[$key] ?? 0) : 0;

            return is_int($value) ? $value : 0;
        };
        $money = static fn (mixed $section, string $key): Money => Money::ofCentavos($int($section, $key));

        $g = $totals['guests'] ?? [];
        $d = $totals['deposits'] ?? [];
        $u = $totals['unpaid'] ?? [];

        return [
            'guests' => $this->guestsForwardedOut($int($g, 'forwarded_in'), $int($g, 'checked_in'), $int($g, 'checked_out')) === $int($g, 'forwarded_out'),
            'deposits' => $this->depositsHeldClose($money($d, 'held_open'), $money($d, 'taken'), $money($d, 'returned'), $money($d, 'forfeited'), $money($d, 'applied'))
                ->equals($money($d, 'held_close')),
            'unpaid' => $this->unpaidForwardedOut($money($u, 'forwarded_in'), $money($u, 'charged'), $money($u, 'collected'))
                ->equals($money($u, 'forwarded_out')),
        ];
    }
}
