<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\ApprovalAction;
use App\Enums\CleaningStatus;
use App\Enums\CleaningTrigger;
use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PosMode;
use App\Enums\PrintDocument;
use App\Enums\PrintFormat;
use App\Enums\RoomStatus;
use App\Enums\RoomStatusReason;
use App\Enums\StayKind;
use App\Enums\StaySource;
use App\Enums\StayState;
use App\Enums\StockMovementKind;
use App\Models\Approval;
use App\Models\Branch;
use App\Models\CleaningTask;
use App\Models\Discount;
use App\Models\ExtensionRate;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PosOrder;
use App\Models\PrintLog;
use App\Models\Product;
use App\Models\Rate;
use App\Models\Room;
use App\Models\RoomStatusLog;
use App\Models\ShiftSession;
use App\Models\Stay;
use App\Models\StayRoom;
use App\Models\StockMovement;
use App\Models\TransferReason;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Writes demo ledger rows the way the Phase 3 actions will (DATABASE_SCHEMA §8),
 * without their checks, so DemoShiftSeeder can replay a whole shift. Seeding only.
 */
final class Ledger
{
    public ShiftSession $shift;

    /** @var Collection<string, Room> */
    private Collection $rooms;

    /** @var Collection<string, Product> */
    private Collection $products;

    public function __construct(public readonly Branch $branch)
    {
        $this->rooms = Room::query()->where('branch_id', $branch->id)->with('roomType')->get()->keyBy('number');
        $this->products = Product::query()->where('branch_id', $branch->id)->get()->keyBy('name');
    }

    /** A branch-local time ("2026-11-11 08:15" by default date) as UTC. */
    public static function at(string $time, string $date = '2026-11-11'): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}", 'Asia/Manila')->utc();
    }

    public function room(string $number): Room
    {
        return $this->rooms[$number];
    }

    /**
     * Walk-in check-in (§8.1): stay, stay_rooms, room and discount lines, the room
     * payment with its allocations, the key deposit, the slip, room → occupied.
     */
    public function checkIn(string $room, string $guest, int|string $option, CarbonImmutable $at, User $by, ?string $discount = null): Stay
    {
        $roomRow = $this->room($room);
        $overnight = $option === 'overnight';
        $hours = $overnight ? 0 : (int) $option;
        $rate = Rate::query()->where('branch_id', $this->branch->id)->where('room_type_id', $roomRow->room_type_id)
            ->where('stay_kind', $overnight ? StayKind::Overnight : StayKind::Hourly)->where('hours', $hours)->firstOrFail();
        $discountRow = $discount === null ? null : Discount::query()->where('branch_id', $this->branch->id)->where('name', $discount)->firstOrFail();
        $roomAmount = $rate->amount?->centavos() ?? 0;
        $discountAmount = match (true) {
            $discountRow === null => 0,
            $discountRow->kind->value === 'fixed' => $discountRow->value,
            default => intdiv($roomAmount * $discountRow->value + 50, 100),
        };

        $this->branch->refresh();
        $number = $this->branch->next_stay_number;
        $this->branch->update(['next_stay_number' => $number + 1]);

        $stay = Stay::query()->create([
            'branch_id' => $this->branch->id,
            'code' => sprintf('%s-%s-%06d', $this->branch->code_prefix, $at->setTimezone('Asia/Manila')->format('y'), $number),
            'state' => StayState::CheckedIn,
            'source' => StaySource::WalkIn,
            'room_id' => $roomRow->id,
            'room_type_id' => $roomRow->room_type_id,
            'guest_name' => $guest,
            'stay_kind' => $overnight ? StayKind::Overnight : StayKind::Hourly,
            'hours' => $overnight ? null : $hours,
            'nights' => $overnight ? 1 : null,
            'discount_id' => $discountRow?->id,
            'quoted_amount' => $roomAmount - $discountAmount,
            'checked_in_at' => $at,
            'expected_checkout_at' => $overnight ? $this->overnightCheckout($at) : $at->addHours($hours),
            'cycle_hours_used' => $hours,
            'checkin_shift_id' => $this->shift->id,
            'checked_in_by' => $by->id,
        ]);

        StayRoom::query()->create(['stay_id' => $stay->id, 'room_id' => $roomRow->id, 'from_at' => $at, 'created_at' => $at]);

        $typeName = $roomRow->roomType->name;
        $this->line($stay, LineKind::Room, $overnight ? "{$typeName} overnight" : "{$typeName} {$hours} hrs", 1, $roomAmount, $at, $by);
        if ($discountRow !== null) {
            $this->line($stay, LineKind::Discount, $discountRow->name, 1, -$discountAmount, $at, $by);
        }

        $this->pay($stay, $at, $by);
        $this->payment($stay, null, PaymentKind::DepositIn, $this->branch->deposit_amount?->centavos() ?? 0, $at, $by, DepositType::Key);
        $this->setStatus($roomRow, RoomStatus::Occupied, RoomStatusReason::CheckIn, $stay, $by, $at);
        $this->printed(PrintDocument::CheckinSlip, $stay->id, $by, $at);

        return $stay;
    }

    /**
     * A POS sale (§8.3): an order, one line per item, a stock movement per tracked item,
     * and the payment when paid now. $stay null = walk-in, always paid.
     *
     * @param  list<array{0: string, 1: int}>  $items  [product name, quantity]
     */
    public function charge(?Stay $stay, array $items, CarbonImmutable $at, User $by, bool $paid = true): PosOrder
    {
        $this->branch->refresh();
        $number = $this->branch->next_pos_number;
        $this->branch->update(['next_pos_number' => $number + 1]);

        $total = 0;
        foreach ($items as [$name, $qty]) {
            $total += ($this->products[$name]->price_amount?->centavos() ?? 0) * $qty;
        }

        $order = PosOrder::query()->create([
            'branch_id' => $this->branch->id,
            'shift_session_id' => $this->shift->id,
            'stay_id' => $stay?->id,
            'mode' => $stay === null ? PosMode::WalkIn : PosMode::RoomCharge,
            'total_amount' => $total,
            'created_by' => $by->id,
            'order_no' => sprintf('P-%06d', $number),
            'occurred_at' => $at,
            'created_at' => $at,
        ]);

        $lines = [];
        foreach ($items as [$name, $qty]) {
            $product = $this->products[$name];
            $line = $this->line($stay, LineKind::from($product->kind->value), $name, $qty, $product->price_amount?->centavos() ?? 0, $at, $by, $order, $product);
            $lines[] = $line->id;

            if ($product->tracks_stock) {
                $product->refresh();
                $balance = $product->stock_on_hand - $qty;
                StockMovement::query()->create([
                    'branch_id' => $this->branch->id, 'product_id' => $product->id, 'shift_session_id' => $this->shift->id,
                    'kind' => StockMovementKind::Sale, 'quantity' => -$qty, 'balance_after' => $balance,
                    'folio_line_id' => $line->id, 'created_by' => $by->id, 'occurred_at' => $at, 'created_at' => $at,
                ]);
                $product->update(['stock_on_hand' => $balance]);
            }
        }

        if ($stay === null) {
            $payment = $this->payment(null, $order, PaymentKind::Payment, $total, $at, $by);
            foreach (FolioLine::query()->whereIn('id', $lines)->get() as $line) {
                PaymentAllocation::query()->create(['payment_id' => $payment->id, 'folio_line_id' => $line->id, 'amount' => $line->amount, 'created_at' => $at]);
            }
        } elseif ($paid) {
            $this->pay($stay, $at, $by, $lines);
        }

        return $order;
    }

    /** Extension inside the 24-hour cycle (§8.5, TRD §4.3 first row). */
    public function extend(Stay $stay, int $hours, CarbonImmutable $at, User $by, bool $paid = true): void
    {
        if ($stay->stay_kind === StayKind::Hourly && $stay->cycle_hours_used + $hours > $this->branch->extension_cycle_hours) {
            throw new LogicException('Demo extensions stay inside the cycle.');
        }

        $amount = ExtensionRate::query()->where('branch_id', $this->branch->id)->where('hours', $hours)->firstOrFail()->amount?->centavos() ?? 0;
        $line = $this->line($stay, LineKind::Extension, "Extension {$hours} hrs", 1, $amount, $at, $by);

        $stay->update([
            'expected_checkout_at' => $stay->expected_checkout_at?->addHours($hours),
            'cycle_hours_used' => $stay->stay_kind === StayKind::Hourly ? $stay->cycle_hours_used + $hours : $stay->cycle_hours_used,
        ]);

        if ($paid) {
            $this->pay($stay, $at, $by, [$line->id]);
        }
    }

    /**
     * One cash payment for the stay's unpaid lines, oldest first (§8.4).
     *
     * @param  list<int>|null  $lineIds  only these lines
     */
    public function pay(Stay $stay, CarbonImmutable $at, User $by, ?array $lineIds = null): ?Payment
    {
        $due = $this->outstanding($stay)->when($lineIds !== null, fn (Collection $c) => $c->whereIn('id', $lineIds));
        $total = (int) $due->sum('due');

        if ($total <= 0) {
            return null;
        }

        $payment = $this->payment($stay, null, PaymentKind::Payment, $total, $at, $by);
        foreach ($due as $row) {
            PaymentAllocation::query()->create(['payment_id' => $payment->id, 'folio_line_id' => $row['id'], 'amount' => $row['due'], 'created_at' => $at]);
        }

        return $payment;
    }

    /**
     * Check-out (§8.8): damages, balance, key deposit returned, stay closed,
     * room → uncleaned with a cleaning task.
     *
     * @param  list<string>  $damages  product names of kind damage
     */
    public function checkOut(Stay $stay, CarbonImmutable $at, User $by, array $damages = []): void
    {
        foreach ($damages as $name) {
            $product = $this->products[$name];
            $this->line($stay, LineKind::Damage, $name, 1, $product->price_amount?->centavos() ?? 0, $at, $by, null, $product);
        }

        $this->pay($stay, $at, $by);

        $held = $this->keyDepositHeld($stay);
        if ($held > 0) {
            $this->payment($stay, null, PaymentKind::DepositRefund, -$held, $at, $by, DepositType::Key);
        }

        $stay->update(['state' => StayState::CheckedOut, 'checked_out_at' => $at, 'checkout_shift_id' => $this->shift->id, 'checked_out_by' => $by->id]);
        StayRoom::query()->where('stay_id', $stay->id)->whereNull('to_at')->update(['to_at' => $at]);

        $room = $this->roomById((int) $stay->room_id);
        $room->update(['last_checkout_at' => $at]);
        $this->setStatus($room, RoomStatus::Uncleaned, RoomStatusReason::CheckOut, $stay, $by, $at);

        CleaningTask::query()->create([
            'branch_id' => $this->branch->id, 'room_id' => $room->id, 'stay_id' => $stay->id,
            'trigger_kind' => CleaningTrigger::CheckOut, 'status' => CleaningStatus::Queued,
            'queued_at' => $at, 'due_at' => $at->addMinutes($this->branch->cleaning_deadline_minutes),
        ]);
    }

    /** A housekeeper cleans the room's queued task (§8.11): room → cleaning → available. */
    public function clean(string $room, CarbonImmutable $start, CarbonImmutable $finish, User $by): void
    {
        $roomRow = $this->room($room);
        $task = CleaningTask::query()->where('room_id', $roomRow->id)->where('status', CleaningStatus::Queued)->firstOrFail();

        $task->update(['status' => CleaningStatus::InProgress, 'started_at' => $start, 'cleaned_by' => $by->id]);
        $this->setStatus($roomRow, RoomStatus::Cleaning, RoomStatusReason::CleanStart, null, $by, $start);
        $task->update(['status' => CleaningStatus::Done, 'finished_at' => $finish, 'is_late' => $finish->greaterThan($task->due_at), 'result' => 'ready']);
        $this->setStatus($roomRow, RoomStatus::Available, RoomStatusReason::CleanFinish, null, $by, $finish);
    }

    /** Transfer with a supervisor PIN (§8.6); the old room goes to maintenance. */
    public function transfer(Stay $stay, string $to, string $reason, CarbonImmutable $at, User $by, User $approver): void
    {
        $from = $this->roomById((int) $stay->room_id);
        $target = $this->room($to);
        $roomLine = FolioLine::query()->where('stay_id', $stay->id)->where('kind', LineKind::Room)->firstOrFail();
        $newPrice = Rate::query()->where('branch_id', $this->branch->id)->where('room_type_id', $target->room_type_id)
            ->where('stay_kind', $stay->stay_kind)->where('hours', (int) $stay->hours)->firstOrFail()->amount?->centavos() ?? 0;
        $difference = $newPrice - ($roomLine->amount?->centavos() ?? 0);
        $reasonRow = TransferReason::query()->where('branch_id', $this->branch->id)->where('name', $reason)->firstOrFail();

        Approval::query()->create([
            'branch_id' => $this->branch->id, 'action' => ApprovalAction::Transfer, 'subject_type' => 'stay', 'subject_id' => $stay->id,
            'requested_by' => $by->id, 'approved_by' => $approver->id, 'reason' => $reason,
            'summary' => sprintf('%s → %s · +₱%s', $from->number, $target->number, number_format($difference / 100, 2)), 'created_at' => $at,
        ]);

        StayRoom::query()->where('stay_id', $stay->id)->whereNull('to_at')->update(['to_at' => $at, 'transfer_reason_id' => $reasonRow->id]);
        StayRoom::query()->create(['stay_id' => $stay->id, 'room_id' => $target->id, 'from_at' => $at, 'created_at' => $at]);
        $stay->update(['room_id' => $target->id, 'room_type_id' => $target->room_type_id]);
        $stay->setRelation('room', $target);

        $line = $this->line($stay, LineKind::TransferDifference, "Transfer {$from->number} → {$target->number}", 1, $difference, $at, $by);
        $this->pay($stay, $at, $by, [$line->id]);

        $this->setStatus($from, RoomStatus::Maintenance, RoomStatusReason::TransferOut, $stay, $by, $at);
        $this->setStatus($target, RoomStatus::Occupied, RoomStatusReason::CheckIn, $stay, $by, $at);
    }

    /** @return Collection<int, array{id: int, due: non-zero-int}> unpaid lines of a stay, oldest first */
    public function outstanding(Stay $stay): Collection
    {
        return FolioLine::query()->where('stay_id', $stay->id)->orderBy('occurred_at')->orderBy('id')->withSum('allocations', 'amount')->get()
            ->map(fn (FolioLine $l): array => ['id' => $l->id, 'due' => (int) (($l->amount?->centavos() ?? 0) - (int) $l->getAttribute('allocations_sum_amount'))])
            ->filter(fn (array $row): bool => $row['due'] !== 0)
            ->values();
    }

    public function keyDepositHeld(Stay $stay): int
    {
        return $this->depositHeld($stay, DepositType::Key);
    }

    public function depositHeld(Stay $stay, DepositType $type): int
    {
        $rows = Payment::query()->where('stay_id', $stay->id)->where('deposit_type', $type)->get();

        return (int) $rows->sum(fn (Payment $p): int => $p->kind === PaymentKind::DepositApplied ? -($p->amount?->centavos() ?? 0) : ($p->amount?->centavos() ?? 0));
    }

    public function payment(?Stay $stay, ?PosOrder $order, PaymentKind $kind, int $amount, CarbonImmutable $at, User $by, ?DepositType $type = null, ?string $note = null): Payment
    {
        return Payment::query()->create([
            'branch_id' => $this->branch->id, 'shift_session_id' => $this->shift->id,
            'stay_id' => $stay?->id, 'pos_order_id' => $order?->id,
            'kind' => $kind, 'method' => PaymentMethod::Cash, 'deposit_type' => $type, 'amount' => $amount,
            'tendered_amount' => $kind === PaymentKind::Payment ? $amount : null, 'change_amount' => $kind === PaymentKind::Payment ? 0 : null,
            'note' => $note, 'created_by' => $by->id, 'occurred_at' => $at, 'created_at' => $at,
        ]);
    }

    public function printed(PrintDocument $document, ?int $subjectId, User $by, CarbonImmutable $at, bool $reprint = false): void
    {
        PrintLog::query()->create([
            'branch_id' => $this->branch->id, 'document' => $document, 'subject_id' => $subjectId, 'format' => PrintFormat::Print,
            'is_reprint' => $reprint, 'user_id' => $by->id, 'shift_session_id' => $this->shift->id, 'created_at' => $at,
        ]);
    }

    private function line(?Stay $stay, LineKind $kind, string $description, int $qty, int $unit, CarbonImmutable $at, User $by, ?PosOrder $order = null, ?Product $product = null): FolioLine
    {
        return FolioLine::query()->create([
            'branch_id' => $this->branch->id, 'shift_session_id' => $this->shift->id,
            'stay_id' => $stay?->id, 'pos_order_id' => $order?->id, 'room_id' => $stay?->room_id,
            'kind' => $kind, 'product_id' => $product?->id, 'description' => $description,
            'quantity' => $qty, 'unit_amount' => $unit, 'amount' => $qty * $unit,
            'created_by' => $by->id, 'occurred_at' => $at, 'created_at' => $at,
        ]);
    }

    private function setStatus(Room $room, RoomStatus $to, RoomStatusReason $reason, ?Stay $stay, User $by, CarbonImmutable $at): void
    {
        RoomStatusLog::query()->create([
            'room_id' => $room->id, 'from_status' => $room->status, 'to_status' => $to, 'reason' => $reason,
            'stay_id' => $stay?->id, 'user_id' => $by->id, 'created_at' => $at,
        ]);
        $room->update(['status' => $to]);
    }

    private function roomById(int $id): Room
    {
        return $this->rooms->firstOrFail(fn (Room $r): bool => $r->id === $id);
    }

    /** The next overnight_checkout_time (branch local) after check-in. */
    private function overnightCheckout(CarbonImmutable $at): CarbonImmutable
    {
        $local = $at->setTimezone('Asia/Manila');
        $out = CarbonImmutable::parse($local->format('Y-m-d').' '.$this->branch->overnight_checkout_time, 'Asia/Manila');

        return ($out->lessThanOrEqualTo($local) ? $out->addDay() : $out)->utc();
    }
}
