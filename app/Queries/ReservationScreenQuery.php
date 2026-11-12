<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\DepositType;
use App\Enums\LineKind;
use App\Enums\PaymentKind;
use App\Enums\ReasonKind;
use App\Enums\StayKind;
use App\Enums\StayState;
use App\Exceptions\InvalidStayOption;
use App\Exceptions\RateNotFound;
use App\Models\Branch;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\User;
use App\Services\Pricing\RateCard;
use App\Services\PricingService;
use App\Support\Clock;
use Carbon\CarbonImmutable;

/**
 * Props of the reservations list (57), its detail sheet, and the new reservation form (58),
 * APP_FLOW §4.19. Prices come from PricingService; availability from RoomQuery by date overlap.
 * Money shown is per reservation (what was paid ahead), never a shift total (rule 9).
 */
final class ReservationScreenQuery
{
    private const PAST_LIMIT = 50;

    public function __construct(
        private readonly Clock $clock,
        private readonly PricingService $pricing,
        private readonly RoomQuery $rooms,
        private readonly StayQuery $stays,
        private readonly StayScreenQuery $screens,
    ) {}

    /**
     * The list (57): one tab of reservations, the five-day strip with the room counts of the
     * picked day, and the counts on the tabs.
     *
     * @return array<string, mixed>
     */
    public function index(Branch $branch, string $tab, ?string $date): array
    {
        $now = $this->clock->now();
        $today = $this->clock->local()->startOfDay();
        $tomorrow = $today->addDay();
        $picked = $this->day($date) ?? null;

        [$from, $to, $states, $newest] = match ($tab) {
            'upcoming' => [$picked ?? $tomorrow, $picked === null ? $today->addYears(2) : $picked->addDay(), [StayState::Reserved, StayState::NoShow, StayState::CheckedIn, StayState::Cancelled], false],
            'past' => [$today->subYear(), $today, [StayState::Reserved, StayState::NoShow, StayState::CheckedIn, StayState::CheckedOut, StayState::Cancelled], true],
            default => [$today, $tomorrow, [StayState::Reserved, StayState::NoShow, StayState::CheckedIn, StayState::CheckedOut], false],
        };

        if ($tab === 'upcoming' && $picked !== null && $picked->lessThan($tomorrow)) {
            [$from, $to] = [$picked, $picked->addDay()];
        }

        $rows = $this->stays->reservations($branch->id, $from->utc(), $to->utc(), $states, $newest);

        if ($tab === 'past') {
            $rows = array_slice($rows, 0, self::PAST_LIMIT);
        }

        $paid = $this->stays->paidAhead(array_map(static fn (Stay $s): int => $s->id, $rows));
        $countDay = $picked ?? $today;

        return [
            'tab' => in_array($tab, ['today', 'upcoming', 'past'], true) ? $tab : 'today',
            'date' => $picked?->toDateString(),
            'rows' => array_map(fn (Stay $s): array => $this->row($s, $paid[$s->id] ?? 0, $today, $now), $rows),
            'counts' => [
                'today' => count($this->stays->reservations($branch->id, $today->utc(), $tomorrow->utc(), [StayState::Reserved, StayState::NoShow, StayState::CheckedIn, StayState::CheckedOut])),
                'upcoming' => count($this->stays->reservations($branch->id, $tomorrow->utc(), $today->addYears(2)->utc(), [StayState::Reserved])),
            ],
            'days' => array_map(static fn (int $i): array => [
                'value' => $today->addDays($i)->toDateString(),
                'label' => $today->addDays($i)->format('D j'),
            ], range(0, 4)),
            'free' => [
                'label' => $countDay->format('D j M'),
                'types' => $this->freeOn($branch, $countDay),
            ],
        ];
    }

    /**
     * The detail sheet: check in, cancel, or settle a no-show's money paid ahead.
     *
     * @return array<string, mixed>
     */
    public function detail(Stay $stay, User $viewer): array
    {
        $today = $this->clock->local()->startOfDay();
        $held = $this->stays->depositHeld($stay->id, DepositType::Guest)->centavos();
        $paid = $this->stays->paidAhead([$stay->id])[$stay->id] ?? 0;
        $row = $this->row($stay, $paid, $today, $this->clock->now());
        $payment = Payment::query()->where('stay_id', $stay->id)->where('kind', PaymentKind::DepositIn)->where('deposit_type', DepositType::Guest)->orderBy('id')->first();

        return [
            ...$row,
            'code' => $stay->code,
            'mobile' => $stay->guest_mobile,
            'checkout_at' => $stay->expected_checkout_at?->toIso8601String(),
            'booked_amount' => $stay->quoted_amount?->centavos() ?? 0,
            'held' => $held,
            'paid_method' => $payment?->method->label(),
            'paid_reference' => $payment?->reference_no,
            'closed_reason' => $stay->closed_reason,
            'can_cancel' => $stay->state === StayState::Reserved,
            'can_settle' => $stay->state === StayState::NoShow && $held > 0,
            'stay_id' => in_array($stay->state, [StayState::CheckedIn, StayState::CheckedOut], true) ? $stay->id : null,
            'reasons' => $this->screens->reasons(ReasonKind::CancelReservation),
            'approvers' => $this->screens->approvers($stay->branch_id, $viewer),
        ];
    }

    /**
     * The form (58): room types with their rooms, the stay options the branch sells, and the
     * payment methods for money paid ahead.
     *
     * @return array<string, mixed>
     */
    public function create(Branch $branch): array
    {
        $card = RateCard::forBranch($branch);
        $types = RoomType::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_active', true)->orderBy('id')->get();
        $rooms = Room::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_active', true)->get(['id', 'number', 'room_type_id']);

        $options = [];

        if ($branch->hourly_enabled) {
            $hours = [];
            foreach ($card->hourly as $byHours) {
                $hours = array_merge($hours, array_keys($byHours));
            }
            $hours = array_values(array_unique($hours));
            sort($hours);

            foreach ($hours as $h) {
                $options[] = ['value' => "hourly-{$h}", 'label' => "{$h} hrs", 'kind' => StayKind::Hourly->value, 'quantity' => $h];
            }
        }

        if ($branch->overnight_enabled) {
            foreach (range(1, min(14, $branch->long_stay_max_days)) as $n) {
                $options[] = ['value' => "overnight-{$n}", 'label' => $n === 1 ? '1 night' : "{$n} nights", 'kind' => StayKind::Overnight->value, 'quantity' => $n];
            }
        }

        return [
            'types' => array_values($types->map(fn (RoomType $t): array => [
                'value' => $t->id,
                'label' => $t->name,
                'rooms' => array_values($rooms->where('room_type_id', $t->id)->sortBy('number', SORT_NATURAL)->map(fn (Room $r): array => ['value' => $r->id, 'label' => $r->number])->all()),
            ])->all()),
            'options' => $options,
            'methods' => $this->screens->methods($branch, false),
            'deposit' => ['name' => $branch->deposit_name, 'amount' => $branch->deposit_enabled ? ($branch->deposit_amount?->centavos() ?? 0) : 0],
            'today' => $this->clock->localDate(),
        ];
    }

    /**
     * The live check on 58: whether a room is free for the whole period, the check-out time and
     * the price. Null until the form has a type, a stay option and an arrival.
     *
     * @return array<string, mixed>|null
     */
    public function quote(Branch $branch, int $typeId, ?int $roomId, StayKind $kind, int $quantity, CarbonImmutable $arrivalAt): ?array
    {
        $type = RoomType::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->find($typeId);

        if ($type === null) {
            return null;
        }

        try {
            $quote = $this->pricing->quoteStay(RateCard::forBranch($branch), $type->id, $kind, $quantity, $arrivalAt);
        } catch (RateNotFound|InvalidStayOption $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'missing_rate' => true];
        }

        $until = $quote->expectedCheckoutAt;
        $checkout = $this->clock->toLocal($until)->format('D j M, g:i A');
        $nights = $kind === StayKind::Overnight ? ($quantity === 1 ? 'the night' : "all {$quantity} nights") : 'the whole stay';
        $room = $roomId === null ? null : Room::query()->withoutGlobalScopes()->where('room_type_id', $type->id)->find($roomId);

        if ($arrivalAt->lessThan($this->clock->now()->subMinutes(5))) {
            $ok = false;
            $message = 'The arrival time has already passed. Pick a later time.';
        } elseif ($room !== null) {
            $ok = $this->rooms->reservableFor($room, $arrivalAt, $until);
            $message = $ok ? "Room {$room->number} is free for {$nights}. Check-out {$checkout}." : "Room {$room->number} is not free for those dates. Pick another room or other dates.";
        } else {
            $free = $this->rooms->freeForType($branch->id, $type->id, $arrivalAt, $until)->count() - $this->rooms->anyReservedCount($branch->id, $type->id, $arrivalAt, $until);
            $ok = $free > 0;
            $message = $ok
                ? "{$free} {$type->name} ".($free === 1 ? 'room is' : 'rooms are')." free for {$nights}. Check-out {$checkout}."
                : "No {$type->name} room is free on ".$this->clock->toLocal($arrivalAt)->format('D j M').'. Pick another room type or other dates.';
        }

        return [
            'ok' => $ok,
            'message' => $message,
            'missing_rate' => false,
            'description' => $quote->description,
            'room_amount' => $quote->roomAmount->centavos(),
            'deposit' => $quote->depositAmount->centavos(),
            'total' => $quote->totalToCollect()->centavos(),
            'checkout_at' => $until->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(Stay $stay, int $paidAhead, CarbonImmutable $today, CarbonImmutable $now): array
    {
        $arrival = $stay->arrival_at === null ? null : $this->clock->toLocal($stay->arrival_at);
        $isToday = $arrival !== null && $arrival->isSameDay($today);
        [$status, $tone] = match ($stay->state) {
            StayState::Reserved => $isToday || ($arrival !== null && $arrival->lessThan($today)) ? ['Arriving', 'info'] : ['Confirmed', 'ok'],
            StayState::NoShow => ['No-show', 'bad'],
            StayState::CheckedIn, StayState::CheckedOut => ['Checked in', 'neutral'],
            StayState::Cancelled => ['Cancelled', 'neutral'],
            default => [$stay->state->label(), 'neutral'],
        };
        $settled = $stay->state === StayState::NoShow && $this->settled($stay);
        $canCheckIn = ($stay->state === StayState::Reserved && $arrival !== null && $arrival->lessThan($today->addDay()))
            || ($stay->state === StayState::NoShow && ! $settled);

        return [
            'id' => $stay->id,
            'arrival_at' => $stay->arrival_at?->toIso8601String(),
            'arrival_today' => $isToday,
            'guest' => $stay->guest_name,
            'room' => $stay->room_id === null ? 'Any '.($stay->roomType->name ?? 'room') : ($stay->room->number ?? '').' · '.($stay->roomType->name ?? ''),
            'stay' => $this->screens->optionLabel($stay) === 'overnight' ? '1 night' : $this->screens->optionLabel($stay),
            'paid_ahead' => $paidAhead > 0 ? $paidAhead : null,
            'status' => $status,
            'tone' => $tone,
            'state' => $stay->state->value,
            'can_check_in' => $canCheckIn,
            'now' => $now->toIso8601String(),
        ];
    }

    private function settled(Stay $stay): bool
    {
        return Payment::query()->where('stay_id', $stay->id)->where('kind', PaymentKind::DepositRefund)->exists()
            || FolioLine::query()->where('stay_id', $stay->id)->where('kind', LineKind::ForfeitedDeposit)->exists();
    }

    /**
     * Rooms of each type free for the picked day, as on 57 ("Standard 19 of 24").
     *
     * @return list<array{name: string, free: int, of: int}>
     */
    private function freeOn(Branch $branch, CarbonImmutable $day): array
    {
        $from = $day->startOfDay()->utc();
        $to = $day->addDay()->startOfDay()->utc();

        if ($from->lessThan($this->clock->now())) {
            $from = $this->clock->now();
        }

        $out = [];

        foreach (RoomType::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_active', true)->orderBy('id')->get() as $type) {
            $free = $this->rooms->freeForType($branch->id, $type->id, $from, $to)->count() - $this->rooms->anyReservedCount($branch->id, $type->id, $from, $to);
            $out[] = ['name' => $type->name, 'free' => max(0, $free), 'of' => $this->rooms->countOfType($branch->id, $type->id)];
        }

        return $out;
    }

    private function day(?string $date): ?CarbonImmutable
    {
        if ($date === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $date, Clock::displayTimezone())?->startOfDay() ?: null;
    }
}
