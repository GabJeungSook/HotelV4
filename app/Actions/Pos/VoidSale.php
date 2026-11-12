<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Actions\Auth\ApproveByPin;
use App\Enums\ApprovalAction;
use App\Enums\PaymentKind;
use App\Enums\PosMode;
use App\Enums\ReasonKind;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Models\FolioLine;
use App\Models\PaymentAllocation;
use App\Models\PosOrder;
use App\Models\Product;
use App\Models\Reason;
use App\Models\Stay;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\PosLedger;
use App\Services\StayLedger;
use App\Support\Money;
use App\Support\ReasonText;
use App\Support\Transaction;

/**
 * Void a POS sale with a reason and a supervisor PIN (19 → sheet 14, APP_FLOW §4.13). Only a
 * sale of the open shift, and not while the shift is closing. Nothing is deleted: approvals
 * (void_sale), a reversing line per line not yet voided, a cash refund with negative
 * allocations for what was paid, and the stock back (sale_reversal). A room-charge sale is
 * reversed on the guest's folio, which must still be checked in.
 *
 * The reason comes from the void-line list (reasons kind void_line): a sale is voided line by line.
 */
final class VoidSale
{
    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly StayLedger $stays,
        private readonly PosLedger $pos,
        private readonly ApproveByPin $approveByPin,
        private readonly Transaction $transaction,
    ) {}

    public function handle(
        User $by,
        PosOrder $order,
        Reason $reason,
        User $approver,
        string $approverPin,
        string $deviceKey,
        ?string $note = null,
    ): PosOrder {
        $text = ReasonText::for($reason, ReasonKind::VoidLine, $note);

        return $this->transaction->run(function () use ($by, $order, $reason, $approver, $approverPin, $deviceKey, $text): PosOrder {
            $shift = $this->currentShift->forWrite($order->branch_id);

            if ($order->shift_session_id !== $shift->id) {
                throw ActionRefused::because("{$order->order_no} was sold in an earlier shift and can no longer be voided.");
            }

            $stay = null;
            if ($order->mode === PosMode::RoomCharge) {
                $stay = Stay::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($order->stay_id);

                if ($stay->state !== StayState::CheckedIn) {
                    throw ActionRefused::stayNotInHouse($stay->code);
                }
            }

            $lines = FolioLine::query()->withoutGlobalScopes()
                ->where('pos_order_id', $order->id)
                ->whereNull('reversal_of_id')
                ->whereDoesntHave('reversal')
                ->orderBy('id')
                ->get()
                ->all();

            if ($lines === []) {
                throw ActionRefused::because("{$order->order_no} is already voided.");
            }

            $approval = $this->approveByPin->handle(
                $approver, $approverPin, $deviceKey, $by, $order->branch_id,
                ApprovalAction::VoidSale, 'pos_order', $order->id, $text,
                "Void sale {$order->order_no} · ".($order->total_amount?->format() ?? ''),
                $reason->id,
            );

            if ($stay !== null) {
                $this->stays->reverse($shift, $stay, $by, $lines, $text, $approval->id);

                return $order;
            }

            $productIds = array_values(array_unique(array_filter(array_map(static fn (FolioLine $l): ?int => $l->product_id, $lines))));
            sort($productIds);
            $products = Product::query()->withoutGlobalScopes()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $paidOn = [];
            foreach (PaymentAllocation::query()->whereIn('folio_line_id', array_map(static fn (FolioLine $l): int => $l->id, $lines))->get() as $allocation) {
                $paidOn[$allocation->folio_line_id] = ($paidOn[$allocation->folio_line_id] ?? Money::zero())->plus($allocation->amount ?? Money::zero());
            }

            $refunds = [];
            $refundTotal = Money::zero();

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $products->get($line->product_id);
                $reversal = $this->pos->line($shift, $order, $by, $product, $line->unit_amount ?? Money::zero(), -$line->quantity, $line, $text, $approval->id);
                $paid = $paidOn[$line->id] ?? Money::zero();

                if (! $paid->isZero()) {
                    $refunds[] = [$reversal, $paid->negate()];
                    $refundTotal = $refundTotal->plus($paid);
                }

                $this->stays->stockBack($shift, $product, $line->quantity, $reversal, $by);
            }

            if ($refundTotal->isPositive()) {
                $refund = $this->pos->cash($shift, $order, $by, PaymentKind::Refund, $refundTotal->negate(), note: $text, approvalId: $approval->id);
                $this->stays->allocate($refund, $refunds);
            }

            return $order;
        });
    }
}
