<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\CleaningStatus;
use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\ProductKind;
use App\Enums\ReasonKind;
use App\Enums\Role;
use App\Enums\StayKind;
use App\Enums\StayState;
use App\Exceptions\DiscountNotAllowed;
use App\Exceptions\InvalidStayOption;
use App\Exceptions\RateNotFound;
use App\Models\Branch;
use App\Models\CleaningTask;
use App\Models\Discount;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Reason;
use App\Models\Room;
use App\Models\Stay;
use App\Models\TransferReason;
use App\Models\User;
use App\Services\Pricing\DiscountTerms;
use App\Services\Pricing\RateCard;
use App\Services\PricingService;
use App\Support\Clock;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Props for the stay screens (6, 8, 9–18). Every price comes from PricingService and every
 * balance from StayQuery; the pages only display what is sent (TRD §3.1, CLAUDE.md rule 6).
 * Amounts are integer centavos.
 */
final class StayScreenQuery
{
    public function __construct(
        private readonly Clock $clock,
        private readonly PricingService $pricing,
        private readonly StayQuery $stays,
        private readonly RoomQuery $rooms,
    ) {}

    /**
     * Walk-in check-in (6): every stay option with its price per discount, so the "To collect"
     * card can follow a click without asking the server again. An option without a rate is
     * sent disabled with "no rate" (52).
     *
     * From a reservation (57 → 6) the guest, the stay option and the room come filled in, and
     * the money paid ahead (the guest deposit held) comes off the total. For an "any room"
     * reservation the free rooms of its type are sent for the desk to pick from.
     *
     * @return array<string, mixed>
     */
    public function checkIn(Room $room, ?Stay $reservation = null): array
    {
        $branch = Branch::query()->findOrFail($room->branch_id);
        $card = RateCard::forBranch($branch);
        $now = $this->clock->now();
        $type = $card->typeName($room->room_type_id);
        $discounts = Discount::query()->where('is_active', true)->orderBy('name')->get();
        $terms = $discounts->map(fn (Discount $d): DiscountTerms => DiscountTerms::of($d))->all();

        $options = [];

        if ($branch->hourly_enabled) {
            $hours = [];
            foreach ($card->hourly as $byHours) {
                $hours = array_merge($hours, array_keys($byHours));
            }
            $hours = array_values(array_unique($hours));
            sort($hours);

            foreach ($hours as $h) {
                $options[] = $this->option("hourly-{$h}", StayKind::Hourly, "{$h} hrs", [$h], $card, $room, $now, $terms, "There is no {$h}-hour rate for {$type} rooms.");
            }
        }

        if ($branch->overnight_enabled) {
            $options[] = $this->option('overnight', StayKind::Overnight, 'Overnight', range(1, $branch->long_stay_max_days), $card, $room, $now, $terms, "There is no overnight rate for {$type} rooms.");
        }

        if ($branch->long_stay_enabled) {
            $options[] = $this->option('long_stay', StayKind::LongStay, 'Long stay', range(1, $branch->long_stay_max_days), $card, $room, $now, $terms, "There is no 24-hour rate for {$type} rooms, so a long stay has no price.", '/day');
        }

        return [
            'room' => $this->roomInfo($room),
            'options' => $options,
            'deposit' => ['name' => $branch->deposit_name, 'amount' => $branch->deposit_enabled ? $branch->deposit_amount?->centavos() ?? 0 : 0],
            'discounts' => array_values($discounts->map(fn (Discount $d): array => ['id' => $d->id, 'name' => $d->name])->all()),
            'methods' => $this->methods($branch, false),
            'now' => $now->toIso8601String(),
            'reservation' => $reservation === null ? null : $this->reservationInfo($reservation),
            'rooms' => $reservation === null || $reservation->room_id !== null ? [] : array_values($this->rooms
                ->free($branch->id, $reservation->room_type_id)
                ->map(fn (Room $r): array => ['value' => $r->id, 'label' => "Room {$r->number}"])
                ->all()),
        ];
    }

    /** @return array<string, mixed> */
    private function reservationInfo(Stay $stay): array
    {
        $quantity = (int) ($stay->hours ?? $stay->nights ?? $stay->days ?? 1);

        return [
            'id' => $stay->id,
            'code' => $stay->code,
            'state' => $stay->state->value,
            'guest_name' => $stay->guest_name,
            'guest_mobile' => $stay->guest_mobile,
            'kind' => $stay->stay_kind->value,
            'quantity' => $quantity,
            'option_key' => match ($stay->stay_kind) {
                StayKind::Hourly => "hourly-{$quantity}",
                StayKind::Overnight => 'overnight',
                StayKind::LongStay => 'long_stay',
            },
            'option_label' => $this->optionLabel($stay),
            'arrival_at' => $stay->arrival_at?->toIso8601String(),
            'any_room' => $stay->room_id === null,
            'booked_amount' => $stay->quoted_amount?->centavos() ?? 0,
            'paid_ahead' => $this->stays->depositHeld($stay->id, DepositType::Guest)->centavos(),
        ];
    }

    /**
     * @param  list<int>  $quantities
     * @param  list<DiscountTerms>  $terms
     * @return array<string, mixed>
     */
    private function option(string $key, StayKind $kind, string $label, array $quantities, RateCard $card, Room $room, CarbonImmutable $now, array $terms, string $missing, string $perUnit = ''): array
    {
        $choices = [];

        foreach ($quantities as $q) {
            try {
                $quote = $this->pricing->quoteStay($card, $room->room_type_id, $kind, $q, $now);
            } catch (RateNotFound|InvalidStayOption) {
                break;
            }

            $byDiscount = [];
            foreach ($terms as $t) {
                try {
                    $byDiscount[(string) $t->id] = $this->pricing->quoteStay($card, $room->room_type_id, $kind, $q, $now, $t)->discountAmount->centavos();
                } catch (DiscountNotAllowed) {
                    $byDiscount[(string) $t->id] = null;
                }
            }

            $choices[] = [
                'quantity' => $q,
                'description' => $quote->description,
                'room_amount' => $quote->roomAmount->centavos(),
                'expected_checkout_at' => $quote->expectedCheckoutAt->toIso8601String(),
                'discounts' => $byDiscount,
            ];
        }

        $first = $choices[0] ?? null;

        return [
            'key' => $key,
            'kind' => $kind->value,
            'label' => $label,
            'price' => $first === null ? null : $first['room_amount'],
            'per_unit' => $perUnit,
            'disabled' => $first === null,
            'missing' => $first === null ? $missing : null,
            'choices' => $choices,
        ];
    }

    /** @return array<string, mixed> */
    private function roomInfo(Room $room): array
    {
        $room->loadMissing(['roomType' => fn ($q) => $q->withoutGlobalScopes(), 'floor' => fn ($q) => $q->withoutGlobalScopes()]);

        return [
            'id' => $room->id,
            'number' => $room->number,
            'type' => $room->roomType->name ?? '',
            'type_id' => $room->room_type_id,
            'floor' => $room->floor === null ? '' : $room->floor->number.'F',
            'status' => $room->status->value,
        ];
    }

    /**
     * The branch's payment methods for a form. A check-in is never paid from deposit.
     *
     * @return list<array{value: string, label: string, needs_reference: bool}>
     */
    public function methods(Branch $branch, bool $withDeposit): array
    {
        $out = [];

        foreach ($branch->payment_methods as $method) {
            if ($method === PaymentMethod::Deposit) {
                continue;
            }

            $out[] = ['value' => $method->value, 'label' => $method->label(), 'needs_reference' => $method !== PaymentMethod::Cash];
        }

        if ($withDeposit) {
            $out[] = ['value' => PaymentMethod::Deposit->value, 'label' => 'Guest deposit', 'needs_reference' => false];
        }

        return $out;
    }

    /**
     * The stay header shared by 8, 9, 12, 13 and 16–18.
     *
     * @return array<string, mixed>
     */
    public function header(Stay $stay): array
    {
        $stay->loadMissing(['room' => fn ($q) => $q->withoutGlobalScopes(), 'roomType' => fn ($q) => $q->withoutGlobalScopes()]);
        $now = $this->clock->now();

        return [
            'id' => $stay->id,
            'code' => $stay->code,
            'state' => $stay->state->value,
            'guest_name' => $stay->guest_name,
            'guest_mobile' => $stay->guest_mobile,
            'plate_number' => $stay->plate_number,
            'room' => $stay->room?->number,
            'room_id' => $stay->room_id,
            'type' => $stay->roomType->name ?? '',
            'kind' => $stay->stay_kind->value,
            'quantity' => (int) ($stay->hours ?? $stay->nights ?? $stay->days ?? 1),
            'checked_in_at' => $stay->checked_in_at?->toIso8601String(),
            'expected_checkout_at' => $stay->expected_checkout_at?->toIso8601String(),
            'checked_out_at' => $stay->checked_out_at?->toIso8601String(),
            'minutes_left' => $stay->expected_checkout_at === null ? null : intdiv($stay->expected_checkout_at->getTimestamp() - $now->getTimestamp(), 60),
            'now' => $now->toIso8601String(),
        ];
    }

    /**
     * The guest folio (9) and its sheets: lines with who added them and their status, the
     * money summary, deposits held, products for Add charge (10), what a payment would pay (11),
     * the reasons and approvers for void and cancel (15, 14), and what a cancel would reverse.
     *
     * @return array<string, mixed>
     */
    public function folio(Stay $stay, User $viewer): array
    {
        $folio = $this->stays->folio($stay->id);
        $branch = Branch::query()->findOrFail($stay->branch_id);
        $names = User::query()->whereIn('id', array_unique(array_column($folio['lines'], 'created_by')))->pluck('name', 'id');

        $lines = [];
        $charges = Money::zero();
        $paid = Money::zero();
        $unpaidCount = 0;
        $unpaidTotal = Money::zero();
        $standingPaid = Money::zero();

        foreach ($folio['lines'] as $l) {
            $charges = $charges->plus($l['amount']);
            $paid = $paid->plus($l['paid']);
            $standing = $l['status'] !== 'reversed';

            if ($standing && $l['status'] === 'unpaid' && $l['amount']->isPositive()) {
                $unpaidCount++;
                $unpaidTotal = $unpaidTotal->plus($l['amount']->minus($l['paid']));
            }

            if ($standing) {
                $standingPaid = $standingPaid->plus($l['paid']);
            }

            $lines[] = [
                'id' => $l['id'],
                'kind' => $l['kind']->value,
                'description' => $l['description'].($l['quantity'] !== 1 && $l['quantity'] !== -1 && ! in_array($l['kind'], [LineKind::Room, LineKind::Extension, LineKind::TransferDifference, LineKind::Discount], true) ? ' × '.abs($l['quantity']) : ''),
                'amount' => $l['amount']->centavos(),
                'paid' => $l['paid']->centavos(),
                'due' => $l['amount']->minus($l['paid'])->centavos(),
                'status' => $l['status'],
                'is_reversal' => $l['reversal_of_id'] !== null,
                'voidable' => $l['status'] !== 'reversed' && $l['kind'] !== LineKind::ForfeitedDeposit && $stay->state === StayState::CheckedIn,
                'at' => $l['occurred_at']?->toIso8601String(),
                'by' => self::firstName((string) ($names[$l['created_by']] ?? '')),
            ];
        }

        $deposits = $folio['deposits'];
        $guestHeld = $deposits['guest'];
        $keyHeld = $deposits['key'];

        return [
            'stay' => $this->header($stay),
            'lines' => $lines,
            'deposits' => [
                ['type' => 'key', 'label' => "{$branch->deposit_name} deposit", 'amount' => $keyHeld->centavos()],
                ['type' => 'guest', 'label' => 'Guest deposit', 'amount' => $guestHeld->centavos()],
            ],
            'summary' => [
                'charges' => $charges->centavos(),
                'paid' => $paid->centavos(),
                'balance' => $folio['balance_due']->centavos(),
                'deposit_held' => $keyHeld->plus($guestHeld)->centavos(),
                'guest_deposit' => $guestHeld->centavos(),
            ],
            'payable' => array_values(array_map(static fn (array $l): array => ['description' => $l['description'], 'due' => $l['due']], array_filter($lines, static fn (array $l): bool => $l['status'] === 'unpaid' && $l['due'] > 0))),
            'products' => $this->products(),
            'methods' => $this->methods($branch, true),
            'reasons' => [
                'void_line' => $this->reasons(ReasonKind::VoidLine),
                'cancel_stay' => $this->reasons(ReasonKind::CancelStay),
            ],
            'cancel' => [
                'refund' => $standingPaid->plus($keyHeld)->plus($guestHeld)->centavos(),
                'unpaid_count' => $unpaidCount,
                'unpaid_total' => $unpaidTotal->centavos(),
            ],
            'approvers' => $this->approvers($stay->branch_id, $viewer),
        ];
    }

    /** @return list<array{id: int, name: string, kind: string, price: int, stock: int|null, low: bool}> */
    public function products(): array
    {
        return array_values(Product::query()
            ->where('is_active', true)
            ->where('outlet', 'frontdesk')
            ->orderBy('name')
            ->get()
            ->map(static fn (Product $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'kind' => $p->kind->value,
                'price' => $p->price_amount?->centavos() ?? 0,
                'stock' => $p->tracks_stock ? $p->stock_on_hand : null,
                'low' => $p->tracks_stock && $p->stock_on_hand > 0 && $p->stock_on_hand <= $p->low_stock_at,
            ])
            ->all());
    }

    /** @return list<array{id: int, name: string, needs_note: bool, needs_manager: bool}> */
    public function reasons(ReasonKind $kind): array
    {
        return array_values(Reason::query()
            ->where('kind', $kind)
            ->where('is_active', true)
            ->orderByRaw("name = 'Other'")
            ->orderBy('name')
            ->get()
            ->map(static fn (Reason $r): array => ['id' => $r->id, 'name' => $r->name, 'needs_note' => $r->needs_note, 'needs_manager' => $r->needs_manager])
            ->all());
    }

    /**
     * Who can approve on sheet 14: a supervisor or admin of the branch, or an owner; active;
     * never the person asking (TRD §9).
     *
     * @return list<array{id: int, name: string, role: string}>
     */
    public function approvers(int $branchId, User $requester): array
    {
        return array_values(User::query()
            ->where('is_active', true)
            ->where('id', '!=', $requester->id)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $branchId)->whereIn('role', [Role::Supervisor, Role::Admin]))->orWhere('role', Role::Owner))
            ->orderByRaw('role = ?', [Role::Owner->value])
            ->orderBy('name')
            ->get(['id', 'name', 'role'])
            ->map(static fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->label()])
            ->all());
    }

    /**
     * Extend (12): every extension length the branch sells, priced for this stay; a length
     * whose rate is missing is sent disabled (52).
     *
     * @return array<string, mixed>
     */
    public function extend(Stay $stay): array
    {
        $branch = Branch::query()->findOrFail($stay->branch_id);
        $card = RateCard::forBranch($branch);
        $candidates = array_keys($card->extension);
        sort($candidates);
        $expected = $stay->expected_checkout_at;
        $options = [];

        foreach ($candidates as $h) {
            try {
                $q = $this->pricing->quoteExtension($card, $stay->room_type_id, $stay->stay_kind, $stay->cycle_hours_used, $h);
                $options[] = [
                    'hours' => $h,
                    'amount' => $q->amount->centavos(),
                    'parts' => array_map(static fn (array $p): array => ['label' => $p['label'], 'amount' => $p['amount']->centavos()], $q->parts),
                    'cycle_hours_used' => $q->cycleHoursUsed,
                    'new_checkout_at' => $expected?->addHours($h)->toIso8601String(),
                    'disabled' => false,
                    'missing' => null,
                ];
            } catch (RateNotFound $e) {
                $options[] = ['hours' => $h, 'amount' => null, 'parts' => [], 'cycle_hours_used' => null, 'new_checkout_at' => null, 'disabled' => true, 'missing' => $e->userMessage()];
            }
        }

        return [
            'stay' => $this->header($stay),
            'options' => $options,
            'rule' => $stay->stay_kind === StayKind::Hourly ? $branch->extension_rule->value : 'block',
            'cycle_hours' => $branch->extension_cycle_hours,
            'cycle_hours_used' => $stay->cycle_hours_used,
            'methods' => $this->methods($branch, false),
            'balance' => $this->stays->balanceDue($stay->id)->centavos(),
        ];
    }

    /**
     * Transfer (13): free rooms with the price difference of moving there, the reasons, and
     * the approvers for sheet 14.
     *
     * @return array<string, mixed>
     */
    public function transfer(Stay $stay, User $viewer): array
    {
        $branch = Branch::query()->findOrFail($stay->branch_id);
        $card = RateCard::forBranch($branch);
        $quantity = (int) ($stay->hours ?? $stay->nights ?? $stay->days ?? 1);
        $charged = Money::ofCentavos((int) FolioLine::query()
            ->where('stay_id', $stay->id)
            ->whereIn('kind', [LineKind::Room, LineKind::TransferDifference])
            ->sum('amount'));
        $byType = [];
        $rooms = [];

        $now = $this->clock->now();
        $until = $stay->expected_checkout_at !== null && $stay->expected_checkout_at->greaterThan($now) ? $stay->expected_checkout_at : $now->addMinute();

        // Rooms reserved for the rest of this stay are left out, as TransferRoom refuses them.
        foreach ($this->rooms->free($stay->branch_id, null, $stay->room_id, $now, $until) as $room) {
            $typeId = $room->room_type_id;

            if (! array_key_exists($typeId, $byType)) {
                try {
                    $q = $this->pricing->quoteTransfer($card, $typeId, $stay->stay_kind, $quantity, $charged);
                    $byType[$typeId] = ['difference' => $q->difference->centavos(), 'new_price' => $q->newPrice->centavos(), 'missing' => null];
                } catch (RateNotFound $e) {
                    $byType[$typeId] = ['difference' => null, 'new_price' => null, 'missing' => $e->userMessage()];
                }
            }

            $rooms[] = [
                'id' => $room->id,
                'number' => $room->number,
                'type' => $room->roomType->name ?? '',
                'type_id' => $typeId,
                ...$byType[$typeId],
            ];
        }

        $header = $this->header($stay);

        return [
            'stay' => $header,
            'charged' => $charged->centavos(),
            'charged_label' => "{$header['type']} ".$this->optionLabel($stay),
            'option_label' => $this->optionLabel($stay),
            'rooms' => $rooms,
            'reasons' => array_values(TransferReason::query()->where('is_active', true)->orderBy('name')->get()
                ->map(static fn (TransferReason $r): array => ['id' => $r->id, 'name' => $r->name, 'needs_note' => $r->needs_note, 'needs_manager' => $r->needs_manager])->all()),
            'approvers' => $this->approvers($stay->branch_id, $viewer),
            'methods' => $this->methods($branch, false),
        ];
    }

    /**
     * Check-out (16–18). Step 1 needs the lost key and remote amounts and the damage list;
     * step 2 the bill and deposits once the step 1 answers are known, worked out the way
     * CheckOutGuest will save them: damages are paid from the key deposit, then the guest
     * deposit; what is left of the deposits is returned (DATABASE_SCHEMA §8.8).
     *
     * @param  array<int, int>  $damages  product id → quantity
     * @return array<string, mixed>
     */
    public function checkout(Stay $stay, ?bool $keyReturned = null, ?bool $remoteReturned = null, array $damages = []): array
    {
        $branch = Branch::query()->findOrFail($stay->branch_id);
        $header = $this->header($stay);

        if ($stay->state !== StayState::CheckedIn) {
            return ['stay' => $header, 'done' => $this->done($stay)];
        }

        $damageProducts = Product::query()->where('is_active', true)->where('kind', ProductKind::Damage)->orderBy('name')->get();
        $damageTotal = Money::zero();

        if ($keyReturned === false) {
            $damageTotal = $damageTotal->plus($branch->lost_key_amount ?? Money::zero());
        }

        if ($remoteReturned === false) {
            $damageTotal = $damageTotal->plus($branch->lost_remote_amount ?? Money::zero());
        }

        foreach ($damageProducts as $p) {
            $qty = max(0, (int) ($damages[$p->id] ?? 0));
            $damageTotal = $damageTotal->plus(($p->price_amount ?? Money::zero())->times($qty));
        }

        $folio = $this->stays->folio($stay->id);
        $charges = Money::sum(array_map(static fn (array $l): Money => $l['amount'], $folio['lines']));
        $paid = Money::sum(array_map(static fn (array $l): Money => $l['paid'], $folio['lines']));
        $key = $folio['deposits']['key'];
        $guest = $folio['deposits']['guest'];
        $fromKey = $damageTotal->min($key);
        $fromGuest = $damageTotal->minus($fromKey)->min($guest);
        $covered = $fromKey->plus($fromGuest);

        return [
            'stay' => $header,
            'done' => null,
            'lost' => [
                'key' => $branch->lost_key_amount?->centavos() ?? 0,
                'remote' => $branch->lost_remote_amount?->centavos() ?? 0,
            ],
            'damage_products' => array_values($damageProducts->map(static fn (Product $p): array => [
                'id' => $p->id, 'name' => $p->name, 'price' => $p->price_amount?->centavos() ?? 0, 'stock' => $p->tracks_stock ? $p->stock_on_hand : null,
            ])->all()),
            'settle' => [
                'charges' => $charges->plus($damageTotal)->centavos(),
                'paid' => $paid->plus($covered)->centavos(),
                'balance' => $folio['balance_due']->plus($damageTotal)->minus($covered)->centavos(),
                'damages' => $damageTotal->centavos(),
                'key_held' => $key->centavos(),
                'guest_held' => $guest->centavos(),
                'deposit_name' => $branch->deposit_name,
                'less_damages' => $covered->centavos(),
                'return' => $key->plus($guest)->minus($covered)->centavos(),
            ],
            'methods' => $this->methods($branch, false),
        ];
    }

    /**
     * The done view (18): how long the guest stayed, what deposit was handed back, the
     * room's new status and the clean-by time.
     *
     * @return array<string, mixed>|null
     */
    public function done(Stay $stay): ?array
    {
        if ($stay->state !== StayState::CheckedOut) {
            return null;
        }

        $returned = (int) Payment::query()
            ->where('stay_id', $stay->id)
            ->where('kind', PaymentKind::DepositRefund)
            ->where('shift_session_id', $stay->checkout_shift_id)
            ->sum('amount');
        $forfeited = (int) FolioLine::query()->where('stay_id', $stay->id)->where('kind', LineKind::ForfeitedDeposit)->sum('amount');
        $task = CleaningTask::query()->where('stay_id', $stay->id)->whereIn('status', [CleaningStatus::Queued, CleaningStatus::InProgress])->latest('id')->first();
        $room = Room::query()->find($stay->room_id);
        $minutes = $stay->checked_in_at === null || $stay->checked_out_at === null ? 0 : intdiv($stay->checked_out_at->getTimestamp() - $stay->checked_in_at->getTimestamp(), 60);

        return [
            'stayed_minutes' => $minutes,
            'left_at' => $stay->checked_out_at?->toIso8601String(),
            'deposit_returned' => abs($returned),
            'deposit_forfeited' => $forfeited,
            'room_status' => $room?->status->value,
            'clean_by' => $task?->due_at?->toIso8601String(),
            'receipt' => $this->receipt($stay),
        ];
    }

    /**
     * What the check-in slip (8) prints.
     *
     * @return array<string, mixed>
     */
    public function slip(Stay $stay): array
    {
        $branch = Branch::query()->findOrFail($stay->branch_id);
        $lines = FolioLine::query()->where('stay_id', $stay->id)->where('shift_session_id', $stay->checkin_shift_id)->whereIn('kind', [LineKind::Room, LineKind::Discount])->orderBy('id')->get();
        $payments = Payment::query()->where('stay_id', $stay->id)->where('shift_session_id', $stay->checkin_shift_id)->whereIn('kind', [PaymentKind::Payment, PaymentKind::DepositIn])->orderBy('id')->get();
        $room = $payments->first(fn (Payment $p): bool => $p->kind === PaymentKind::Payment);
        $key = $payments->first(fn (Payment $p): bool => $p->kind === PaymentKind::DepositIn && $p->deposit_type === DepositType::Key);
        // Only the change kept at check-in; money paid ahead on a reservation is a guest deposit_in too.
        $kept = $payments->first(fn (Payment $p): bool => $p->kind === PaymentKind::DepositIn && $p->deposit_type === DepositType::Guest && $p->note === 'change kept as deposit');
        $total = Money::sum($lines->map(fn (FolioLine $l): Money => $l->amount ?? Money::zero())->all())->plus($key->amount ?? Money::zero());
        $by = User::query()->find($stay->checked_in_by);
        // Money paid ahead on a reservation, applied to the room at check-in (APP_FLOW §4.19).
        $paidAhead = (int) Payment::query()->where('stay_id', $stay->id)->where('shift_session_id', $stay->checkin_shift_id)
            ->where('kind', PaymentKind::DepositApplied)->where('note', 'paid ahead, applied at check-in')->sum('amount');

        return [
            'header' => $branch->slip_header,
            'deposit_name' => $branch->deposit_name,
            'paid_ahead' => $paidAhead > 0 ? $paidAhead : null,
            'lines' => array_values($lines->map(fn (FolioLine $l): array => ['label' => $l->kind === LineKind::Room ? 'Room '.$this->optionLabel($stay) : $l->description, 'amount' => $l->amount?->centavos() ?? 0])->all()),
            'deposit' => $key?->amount?->centavos(),
            'total' => $total->centavos(),
            'method' => $room?->method->label() ?? $key?->method->label() ?? 'Cash',
            'tendered' => $room?->tendered_amount?->centavos() ?? $room?->amount?->centavos() ?? $key?->amount?->centavos(),
            'change' => $room?->change_amount?->centavos() ?? 0,
            'kept_as_deposit' => $kept?->amount?->centavos(),
            'reference_no' => $room->reference_no ?? $key->reference_no ?? null,
            'by' => $by === null ? '' : self::shortName($by->name),
        ];
    }

    /**
     * The check-out receipt (18): every line and payment of the stay.
     *
     * @return array<string, mixed>
     */
    private function receipt(Stay $stay): array
    {
        $branch = Branch::query()->findOrFail($stay->branch_id);
        $folio = $this->stays->folio($stay->id);
        $by = User::query()->find($stay->checked_out_by);

        return [
            'header' => $branch->slip_header,
            'lines' => array_map(static fn (array $l): array => ['label' => $l['description'], 'amount' => $l['amount']->centavos()], $folio['lines']),
            'payments' => array_map(static fn (Payment $p): array => [
                'label' => match ($p->kind) {
                    PaymentKind::Payment => 'Paid '.$p->method->label(),
                    PaymentKind::DepositIn => 'Deposit in',
                    PaymentKind::DepositRefund => 'Deposit returned',
                    PaymentKind::DepositApplied => 'From deposit',
                    PaymentKind::Refund => 'Refund',
                },
                'amount' => $p->amount?->centavos() ?? 0,
            ], $folio['payments']),
            'total' => Money::sum(array_map(static fn (array $l): Money => $l['amount'], $folio['lines']))->centavos(),
            'by' => $by === null ? '' : self::shortName($by->name),
        ];
    }

    public function optionLabel(Stay $stay): string
    {
        return match ($stay->stay_kind) {
            StayKind::Hourly => ($stay->hours ?? 0).' hrs',
            StayKind::Overnight => ($stay->nights ?? 1) === 1 ? 'overnight' : ($stay->nights ?? 1).' nights',
            StayKind::LongStay => ($stay->days ?? 1).' '.(($stay->days ?? 1) === 1 ? 'day' : 'days'),
        };
    }

    /** "Jessa Mae Dela Cruz" → "Jessa Mae", "Rhea Bautista" → "Rhea" (the folio's quiet second line). */
    public static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return count($parts) > 2 ? $parts[0].' '.$parts[1] : ($parts[0] ?? '');
    }

    /** "Jessa Mae Dela Cruz" → "Jessa Mae D.", "Rhea Bautista" → "Rhea B." (as UserMenu shortens it). */
    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return match (true) {
            count($parts) > 2 => $parts[0].' '.$parts[1].' '.mb_substr($parts[2], 0, 1).'.',
            count($parts) === 2 => $parts[0].' '.mb_substr($parts[1], 0, 1).'.',
            default => $parts[0] ?? '',
        };
    }
}
