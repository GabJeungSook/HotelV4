<?php

declare(strict_types=1);

namespace App\Actions\Stays;

use App\Actions\Auth\ApproveByPin;
use App\Enums\ApprovalAction;
use App\Enums\DepositType;
use App\Enums\ReasonKind;
use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Enums\StayState;
use App\Exceptions\ActionRefused;
use App\Models\FolioLine;
use App\Models\Reason;
use App\Models\Room;
use App\Models\Stay;
use App\Models\StayRoom;
use App\Models\User;
use App\Services\CurrentShift;
use App\Services\RoomStatusService;
use App\Services\StayLedger;
use App\Support\Clock;
use App\Support\ReasonText;
use App\Support\Transaction;

/**
 * Cancel a check-in made by mistake, with a reason and a supervisor's approval (dialog 15 →
 * sheet 14, DATABASE_SCHEMA §8.9). Every line still standing is reversed and what was paid on
 * it refunded; stock comes back; every deposit held is returned; the stay becomes cancelled
 * and the room goes back to available with no cleaning task.
 */
final class CancelStay
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CurrentShift $currentShift,
        private readonly RoomStatusService $rooms,
        private readonly StayLedger $ledger,
        private readonly ApproveByPin $approveByPin,
        private readonly Transaction $transaction,
    ) {}

    public function handle(
        User $by,
        Stay $stay,
        Reason $reason,
        User $approver,
        string $approverPin,
        string $deviceKey,
        ?string $note = null,
    ): Stay {
        $text = ReasonText::for($reason, ReasonKind::CancelStay, $note);

        return $this->transaction->run(function () use ($by, $stay, $reason, $approver, $approverPin, $deviceKey, $text): Stay {
            // Lock order: shift → room → stay → products (inside reverse()).
            $shift = $this->currentShift->forWrite($stay->branch_id);
            $room = Room::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stay->room_id);
            $locked = Stay::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($stay->id);

            if ($locked->state !== StayState::CheckedIn || $locked->room_id !== $room->id) {
                throw ActionRefused::stayNotInHouse($locked->code);
            }

            $approval = $this->approveByPin->handle(
                $approver, $approverPin, $deviceKey, $by, $locked->branch_id,
                ApprovalAction::CancelStay, 'stay', $locked->id, $text,
                "{$locked->code} · Room {$room->number} cancelled",
                $reason->id,
            );

            $standing = FolioLine::query()->withoutGlobalScopes()
                ->where('stay_id', $locked->id)
                ->whereNull('reversal_of_id')
                ->whereDoesntHave('reversal')
                ->orderBy('id')
                ->get()
                ->all();

            $this->ledger->reverse($shift, $locked, $by, array_values($standing), $text, $approval->id);

            foreach ([DepositType::Key, DepositType::Guest] as $type) {
                $this->ledger->refundDeposit($shift, $locked, $by, $type, 'stay cancelled', $approval->id);
            }

            $now = $this->clock->now();
            $locked->update(['state' => StayState::Cancelled, 'closed_reason' => mb_substr($text, 0, 255)]);
            StayRoom::query()->where('stay_id', $locked->id)->whereNull('to_at')->update(['to_at' => $now]);
            $this->rooms->transition($room, RoomStatus::Available, RoomStatusReason::Cancel, $locked, $by);

            return $locked;
        });
    }
}
