<?php

declare(strict_types=1);

namespace App\Actions\Pos;

use App\Actions\Auth\ApproveByPin;
use App\Enums\ApprovalAction;
use App\Enums\ReasonKind;
use App\Enums\StockMovementKind;
use App\Exceptions\ActionRefused;
use App\Models\Product;
use App\Models\Reason;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\StockBook;
use App\Support\ReasonText;
use App\Support\Transaction;

/**
 * Record wastage (form on screen 20, APP_FLOW §4.14): stock_movements kind wastage with a
 * negative quantity, the reason picked from the wastage list (reasons kind wastage, stored on
 * stock_movements.reason_id) and a note, which wastage always needs (stock_movements_note_chk).
 * Refused if it would take the balance below zero. A reason with needs_manager asks a
 * supervisor PIN (approvals.action reason_requires_manager, TRD §4.12).
 */
final class RecordWastage
{
    public function __construct(
        private readonly CurrentShift $currentShift,
        private readonly StockBook $stock,
        private readonly ApproveByPin $approveByPin,
        private readonly Transaction $transaction,
    ) {}

    public function handle(
        User $by,
        int $branchId,
        int $productId,
        int $quantity,
        Reason $reason,
        string $note,
        ?User $approver = null,
        ?string $approverPin = null,
        ?string $deviceKey = null,
    ): StockMovement {
        if ($quantity < 1) {
            throw ActionRefused::because('Type how many pieces were wasted.');
        }

        $note = trim($note);

        if ($note === '') {
            throw ActionRefused::noteRequired('Wastage');
        }

        $text = ReasonText::for($reason, ReasonKind::Wastage, $note);

        return $this->transaction->run(function () use ($by, $branchId, $productId, $quantity, $reason, $note, $text, $approver, $approverPin, $deviceKey): StockMovement {
            $shift = $this->currentShift->forWrite($branchId);
            $product = Product::query()->withoutGlobalScopes()->where('branch_id', $branchId)->lockForUpdate()->findOrFail($productId);

            if (! $product->tracks_stock) {
                throw ActionRefused::because("{$product->name} does not keep stock.");
            }

            if ($product->stock_on_hand < $quantity) {
                throw ActionRefused::because("Only {$product->stock_on_hand} {$product->name} on hand. Stock cannot go below zero.");
            }

            $movement = $this->stock->move($shift, $product, StockMovementKind::Wastage, -$quantity, $by, reasonId: $reason->id, note: $note);

            if ($reason->needs_manager) {
                if ($approver === null || $approverPin === null || $deviceKey === null) {
                    throw ActionRefused::because("{$reason->name} needs a supervisor PIN.");
                }

                $this->approveByPin->handle(
                    $approver, $approverPin, $deviceKey, $by, $branchId,
                    ApprovalAction::ReasonRequiresManager, 'stock_movement', $movement->id,
                    $text, "Wastage · {$product->name} × {$quantity}",
                    $reason->id,
                );
            }

            return $movement;
        });
    }
}
