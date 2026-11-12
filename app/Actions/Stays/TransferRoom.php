<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Actions\Auth\ApproveByPin;
use App\Enums\ApprovalAction;
use App\Enums\CleaningTrigger;
use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Exceptions\RoomNotAvailable;
use App\Exceptions\RoomTaken;
use App\Exceptions\ShiftStale;
use App\Models\Branch;
use App\Models\FolioLine;
use App\Models\Room;
use App\Models\Stay;
use App\Models\StayRoom;
use App\Models\TransferReason;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\Pricing\RateCard;
use App\Services\PricingService;
use App\Services\RoomStatusService;
use App\Services\StayLedger;
use App\Support\Clock;
use App\Support\DuplicateKey;
use App\Support\Money;
use App\Support\Transaction;
use Illuminate\Database\QueryException;

/**
 * Move a stay to another room with a supervisor's approval (screens 13 → 14,
 * DATABASE_SCHEMA §8.6, APP_FLOW §4.10, TRD §4.4).
 *
 * The original room line and the expected check-out are never touched. An upgrade adds a
 * positive transfer_difference line (and may take the payment now); a downgrade adds a
 * negative one, settled by a cash refund that goes straight back in as a guest deposit, so
 * the drawer is unchanged and the deposit rises. The old room becomes uncleaned (with a
 * cleaning task) or maintenance; the new room becomes occupied.
 */
final class TransferRoom
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CurrentShift $currentShift,
        private readonly PricingService $pricing,
        private readonly RoomStatusService $rooms,
        private readonly StayLedger $ledger,
        private readonly ApproveByPin $approveByPin,
        private readonly Transaction $transaction,
    ) {}

    public function handle(
        User $by,
        Stay $stay,
        Room $target,
        TransferReason $reason,
        User $approver,
        string $approverPin,
        string $deviceKey,
        bool $oldRoomToMaintenance = false,
        ?string $note = null,
        ?PaymentMethod $payWith = null,
        ?Money $amountGiven = null,
        ?string $referenceNo = null,
    ): Stay {
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($reason->needs_note && $note === null) {
            throw ActionRefused::noteRequired($reason->name);
        }

        return $this->transaction->run(function () use ($by, $stay, $target, $reason, $approver, $approverPin, $deviceKey, $oldRoomToMaintenance, $note, $payWith, $amountGiven, $referenceNo): Stay {
            // Lock order: shift → both rooms by id → stay (DATABASE_SCHEMA §8).
            $shift = $this->currentShift->forWrite($stay->branch_id);
            $current = Stay::query()->withoutGlobalScopes()->findOrFail($stay->id);
            $roomIds = [(int) $current->room_id, $target->id];
            sort($roomIds);
            $lockedRooms = Room::query()->withoutGlobalScopes()->whereIn('id', $roomIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $locked = Stay::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stay->id);

            if ($locked->state !== StayState::CheckedIn || $locked->room_id === null) {
                throw ActionRefused::stayNotInHouse($locked->code);
            }

            $from = $lockedRooms[$locked->room_id];
            $to = $lockedRooms[$target->id] ?? throw RoomNotAvailable::room($target->number);

            if ($from->id === $to->id) {
                throw ActionRefused::because('Pick a different room.');
            }

            if ($to->branch_id !== $locked->branch_id || $to->status !== RoomStatus::Available || ! $to->is_active || $this->reservedDuring($to, $locked)) {
                throw RoomNotAvailable::room($to->number);
            }

            $charged = Money::ofCentavos((int) FolioLine::query()->withoutGlobalScopes()
                ->where('stay_id', $locked->id)
                ->whereIn('kind', [LineKind::Room, LineKind::TransferDifference])
                ->sum('amount'));
            $quote = $this->pricing->quoteTransfer(
                RateCard::forBranch(Branch::query()->findOrFail($locked->branch_id)),
                $to->room_type_id, $locked->stay_kind, $this->quantity($locked), $charged,
            );
            $difference = $quote->difference;

            if (! $difference->isZero() && $this->currentShift->isStale($shift)) {
                throw ShiftStale::make();
            }

            $sign = $difference->isNegative() ? '−' : '+';
            $approval = $this->approveByPin->handle(
                $approver, $approverPin, $deviceKey, $by, $locked->branch_id,
                ApprovalAction::Transfer, 'stay', $locked->id,
                $reason->name.($note === null ? '' : ' — '.$note),
                "{$from->number} → {$to->number} · {$sign}".$difference->abs()->format(),
            );

            $now = $this->clock->now();
            StayRoom::query()->where('stay_id', $locked->id)->whereNull('to_at')->update(['to_at' => $now, 'transfer_reason_id' => $reason->id]);
            StayRoom::query()->create(['stay_id' => $locked->id, 'room_id' => $to->id, 'from_at' => $now, 'created_at' => $now]);

            try {
                $locked->update(['room_id' => $to->id, 'room_type_id' => $to->room_type_id]);
            } catch (QueryException $e) {
                if (DuplicateKey::on($e, 'stays_one_active_per_room')) {
                    throw RoomTaken::room($to->number);
                }

                throw $e;
            }

            $description = "Transfer {$from->number} → {$to->number}";

            if ($difference->isPositive()) {
                $this->ledger->line($shift, $locked, $by, LineKind::TransferDifference, $description, $difference, approvalId: $approval->id);

                if ($payWith !== null) {
                    $this->ledger->collect($shift, $locked, $by, $payWith, $amountGiven ?? $difference, $referenceNo);
                }
            } elseif ($difference->isNegative()) {
                $line = $this->ledger->line($shift, $locked, $by, LineKind::TransferDifference, $description, $difference, approvalId: $approval->id);
                $refund = $this->ledger->payment($shift, $locked, $by, PaymentKind::Refund, PaymentMethod::Cash, $difference, note: 'transfer credit', approvalId: $approval->id);
                $this->ledger->allocate($refund, [[$line, $difference]]);
                $this->ledger->payment($shift, $locked, $by, PaymentKind::DepositIn, PaymentMethod::Cash, $difference->negate(), DepositType::Guest, note: 'transfer credit', approvalId: $approval->id);
            }

            $this->rooms->vacate($from, $locked, RoomStatusReason::TransferOut, CleaningTrigger::TransferOut, $oldRoomToMaintenance, $by);
            $this->rooms->transition($to, RoomStatus::Occupied, RoomStatusReason::CheckIn, $locked, $by);

            return $locked;
        });
    }

    /** A reserved stay for the target room overlapping the rest of this stay (DATABASE_SCHEMA §8.6 step 2). */
    private function reservedDuring(Room $room, Stay $stay): bool
    {
        return Stay::query()->withoutGlobalScopes()
            ->where('room_id', $room->id)
            ->where('state', StayState::Reserved)
            ->where('arrival_at', '<', $stay->expected_checkout_at)
            ->where('expected_checkout_at', '>', $this->clock->now())
            ->exists();
    }

    private function quantity(Stay $stay): int
    {
        return (int) ($stay->hours ?? $stay->nights ?? $stay->days ?? 1);
    }
}
