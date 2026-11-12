<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PosOrder;
use App\Models\Product;
use App\Models\ShiftSession;
use App\Models\User;
use App\Support\Clock;
use App\Support\Money;

/**
 * The rows of a walk-in POS sale that have no stay (APP_FLOW §4.13): folio lines with
 * pos_order_id set and stay_id empty, and the cash payment or refund tied to the order.
 * Runs inside the calling action's transaction; stock and allocations go through StayLedger.
 */
final class PosLedger
{
    public function __construct(private readonly Clock $clock) {}

    public function line(
        ShiftSession $shift,
        PosOrder $order,
        User $by,
        Product $product,
        Money $unit,
        int $quantity,
        ?FolioLine $reversalOf = null,
        ?string $reversalReason = null,
        ?int $approvalId = null,
    ): FolioLine {
        $now = $this->clock->now();
        $name = $reversalOf !== null ? 'Void: '.$reversalOf->description : $product->name;

        return FolioLine::query()->create([
            'branch_id' => $order->branch_id,
            'shift_session_id' => $shift->id,
            'stay_id' => null,
            'pos_order_id' => $order->id,
            'room_id' => null,
            'kind' => $reversalOf !== null ? $reversalOf->kind : LineKind::from($product->kind->value),
            'product_id' => $product->id,
            'description' => mb_substr($name, 0, 120),
            'quantity' => $quantity,
            'unit_amount' => $unit,
            'amount' => $unit->times($quantity),
            'reversal_of_id' => $reversalOf?->id,
            'reversal_reason' => $reversalReason === null ? null : mb_substr($reversalReason, 0, 255),
            'approval_id' => $approvalId,
            'created_by' => $by->id,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    /** The cash payment of a walk-in sale (positive) or its refund on a void (negative). */
    public function cash(
        ShiftSession $shift,
        PosOrder $order,
        User $by,
        PaymentKind $kind,
        Money $amount,
        ?Money $tendered = null,
        ?Money $change = null,
        ?string $note = null,
        ?int $approvalId = null,
    ): Payment {
        $now = $this->clock->now();

        return Payment::query()->create([
            'branch_id' => $order->branch_id,
            'shift_session_id' => $shift->id,
            'stay_id' => null,
            'pos_order_id' => $order->id,
            'kind' => $kind,
            'method' => PaymentMethod::Cash,
            'amount' => $amount,
            'tendered_amount' => $tendered,
            'change_amount' => $change,
            'note' => $note === null ? null : mb_substr($note, 0, 255),
            'approval_id' => $approvalId,
            'created_by' => $by->id,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }
}
