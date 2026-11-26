<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\StayKind;
use App\Enums\StayState;
use App\Exceptions\RateNotFound;
use App\Models\Branch;
use App\Models\Floor;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Stay;
use App\Services\KioskOffer;
use App\Services\Pricing\RateCard;
use App\Services\RoomRotation;
use App\Support\Clock;
use Carbon\CarbonImmutable;

/**
 * Props of the kiosk screens (27–31b, APP_FLOW §4.18). Every price and every room comes from
 * here: RoomRotation picks one room per type, KioskOffer prices it with the kiosk discount.
 * The kiosk pages only show what they are sent and send back a type, hours, a name and a mobile.
 * Amounts are integer centavos.
 */
final class KioskScreenQuery
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RoomQuery $rooms,
        private readonly RoomRotation $rotation,
        private readonly KioskOffer $offer,
    ) {}

    /**
     * Welcome (27) and no rooms (31): the house rules and how many rooms the kiosk can offer.
     *
     * @return array<string, mixed>
     */
    public function welcome(Branch $branch): array
    {
        $free = 0;

        foreach ($this->types($branch) as $type) {
            $free += $type['free'];
        }

        return [
            'hotel' => $branch->name,
            'rules' => self::rules((string) $branch->house_rules),
            'free' => $free,
            'poll' => (int) config('hotel.polling.kiosk_availability'),
        ];
    }

    /**
     * Room and hours (28): one offered room per type (or "Full"), and the hour options with the
     * room price before any discount.
     *
     * @return array<string, mixed>
     */
    public function start(Branch $branch): array
    {
        return ['types' => array_map(static fn (array $t): array => array_diff_key($t, ['free' => true]), $this->types($branch))];
    }

    /**
     * Your name (29): what the front desk will collect for this type and hours, with the kiosk
     * discount applied. Null when the type has no free room or no such hour option.
     *
     * @return array<string, mixed>|null
     */
    public function details(Branch $branch, int $roomTypeId, StayKind $kind, int $quantity): ?array
    {
        $type = RoomType::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_active', true)->find($roomTypeId);

        if ($type === null) {
            return null;
        }

        $card = RateCard::forBranch($branch);

        if ($kind === StayKind::LongStay) {
            if ($quantity < 1 || $quantity > $this->offer->maxDays($branch, $card, $type->id, $this->clock->now())) {
                return null;
            }
        } elseif (! in_array($quantity, $this->offer->hours($card, $type->id), true)) {
            return null;
        }

        $room = $this->offered($branch, $card, $type->id, $kind, $quantity);

        if ($room === null) {
            return null;
        }

        ['quote' => $quote, 'discount' => $discount, 'selectable' => $selectable] = $this->offer->quote($branch->id, $card, $type->id, $kind, $quantity, $this->clock->now(), $room->id, $room->floor_id);
        $label = self::stayLabel($kind, $quantity);

        return [
            'type' => ['id' => $type->id, 'name' => $type->name],
            'room' => $this->roomLabel($room),
            'kind' => $kind->value,
            'quantity' => $quantity,
            'stay_label' => $label,
            'room_amount' => $quote->roomAmount->centavos(),
            // The promo that applies by itself (no ID). Null when none is offered on this rate.
            'discount' => $discount === null ? null : [
                'name' => $discount->name,
                'amount' => $quote->discountAmount->centavos(),
                'text' => "{$discount->name} discount for {$quantity}-".($kind === StayKind::LongStay ? 'day' : 'hour').' stays.',
            ],
            // Guest-ticked discounts allowed on this rate (senior citizen, PWD): each with the bill
            // it would produce, so the kiosk can offer a toggle and show the effect with no round-trip.
            'selectable' => array_map(fn (array $s): array => [
                'id' => (int) $s['discount']->id,
                'name' => $s['discount']->name,
                'discount_amount' => $s['quote']->discountAmount->centavos(),
                'total' => $s['quote']->totalToCollect()->centavos(),
            ], $selectable),
            'deposit' => ['name' => $branch->deposit_name, 'amount' => $quote->depositAmount->centavos()],
            'total' => $quote->totalToCollect()->centavos(),
        ];
    }

    /** "6 hours" or "2 days", for labels and the discount note. */
    private static function stayLabel(StayKind $kind, int $quantity): string
    {
        return $kind === StayKind::LongStay ? $quantity.' '.($quantity === 1 ? 'day' : 'days') : $quantity.' hours';
    }

    /**
     * Room held (30): the code, the amount to pay at the desk and the countdown.
     *
     * @return array<string, mixed>
     */
    public function held(Stay $stay): array
    {
        $deposit = Branch::query()->withoutGlobalScopes()->find($stay->branch_id);
        $depositAmount = $deposit !== null && $deposit->deposit_enabled ? ($deposit->deposit_amount?->centavos() ?? 0) : 0;
        $room = $stay->room_id === null ? null : Room::query()->withoutGlobalScopes()->find($stay->room_id);

        return [
            'code' => $stay->code,
            'room' => $room?->number,
            'state' => $stay->state->value,
            'amount' => ($stay->quoted_amount?->centavos() ?? 0) + $depositAmount,
            'expires_at' => $stay->hold_expires_at?->toIso8601String(),
            'now' => $this->clock->now()->toIso8601String(),
            'held' => $stay->state === StayState::Held,
        ];
    }

    /**
     * Each active type with a price: its offered room (or full), the hour and whole-day options,
     * and the free count.
     *
     * @return list<array{id: int, name: string, description: string|null, room: array{id: int, number: string, label: string}|null, full: bool, options: list<array{hours: int, amount: int}>, long_stay: array{max_days: int, per_day: int}|null, free: int}>
     */
    private function types(Branch $branch): array
    {
        if (! $branch->hourly_enabled && ! $branch->long_stay_enabled) {
            return [];
        }

        $card = RateCard::forBranch($branch);
        $now = $this->clock->now();
        $out = [];

        $types = RoomType::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_active', true)->orderBy('id')->get();

        foreach ($types as $type) {
            $hourOptions = $branch->hourly_enabled ? $this->offer->hours($card, $type->id) : [];
            $maxDays = $this->offer->maxDays($branch, $card, $type->id, $now);

            if ($hourOptions === [] && $maxDays <= 0) {
                continue;
            }

            // Offer the room first, then price its own floor/room rate; HoldRoom checks again for
            // the stay chosen. The option set and check-out time do not depend on the room's rate.
            [$firstKind, $firstQty] = $hourOptions !== [] ? [StayKind::Hourly, $hourOptions[0]] : [StayKind::LongStay, 1];
            $eligible = $this->rotation->order($this->rooms->kioskCandidates($branch->id, $type->id, $now, $this->period($branch, $card, $type->id, $firstKind, $firstQty)));
            $first = $eligible[0] ?? null;
            $room = $first === null ? null : Room::query()->withoutGlobalScopes()->find($first->id);
            $roomId = $room?->id;
            $floorId = $room?->floor_id;

            $options = [];
            foreach ($hourOptions as $h) {
                try {
                    $options[] = ['hours' => $h, 'amount' => $this->offer->quote($branch->id, $card, $type->id, StayKind::Hourly, $h, $now, $roomId, $floorId)['quote']->roomAmount->centavos()];
                } catch (RateNotFound) {
                    continue;
                }
            }

            $longStay = $maxDays > 0
                ? ['max_days' => $maxDays, 'per_day' => $this->offer->quote($branch->id, $card, $type->id, StayKind::LongStay, 1, $now, $roomId, $floorId)['quote']->roomAmount->centavos()]
                : null;

            if ($options === [] && $longStay === null) {
                continue;
            }

            $out[] = [
                'id' => $type->id,
                'name' => $type->name,
                'description' => $type->description,
                'room' => $room === null ? null : $this->roomLabel($room),
                'full' => $room === null,
                'options' => $options,
                'long_stay' => $longStay,
                'free' => count($eligible),
            ];
        }

        return $out;
    }

    private function offered(Branch $branch, RateCard $card, int $typeId, StayKind $kind, int $quantity): ?Room
    {
        $next = $this->rotation->next($this->rooms->kioskCandidates($branch->id, $typeId, $this->clock->now(), $this->period($branch, $card, $typeId, $kind, $quantity)));

        return $next === null ? null : Room::query()->withoutGlobalScopes()->find($next->id);
    }

    /** The latest a kiosk stay of this kind and length could end: started at the end of the hold. */
    private function period(Branch $branch, RateCard $card, int $typeId, StayKind $kind, int $quantity): CarbonImmutable
    {
        $start = $this->clock->now()->addMinutes($branch->kiosk_hold_minutes);

        return $this->offer->quote($branch->id, $card, $typeId, $kind, $quantity, $start)['quote']->expectedCheckoutAt;
    }

    /** @return array{id: int, number: string, label: string} */
    private function roomLabel(Room $room): array
    {
        $floor = Floor::query()->withoutGlobalScopes()->find($room->floor_id);

        return ['id' => $room->id, 'number' => $room->number, 'label' => "Room {$room->number}".($floor === null ? '' : ' · '.self::ordinal($floor->number).' floor')];
    }

    /**
     * The house rules as a numbered list: one rule per line, or per sentence when written as
     * one paragraph (branches.house_rules is free text, edited on 46).
     *
     * @return list<string>
     */
    public static function rules(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $parts = str_contains($text, "\n") ? preg_split('/\R+/', $text) : preg_split('/(?<=[.!?])\s+/', $text);

        return array_values(array_filter(array_map(static fn (string $r): string => trim((string) preg_replace('/^\s*(?:[-•*]|\d+[.)])\s*/u', '', $r)), $parts ?: []), static fn (string $r): bool => $r !== ''));
    }

    public static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : match ($n % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };

        return $n.$suffix;
    }
}
