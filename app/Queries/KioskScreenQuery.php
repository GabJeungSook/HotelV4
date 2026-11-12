<?php

declare(strict_types=1);

namespace App\Queries;

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
    public function details(Branch $branch, int $roomTypeId, int $hours): ?array
    {
        $type = RoomType::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_active', true)->find($roomTypeId);

        if ($type === null) {
            return null;
        }

        $card = RateCard::forBranch($branch);

        if (! in_array($hours, $this->offer->hours($card, $type->id), true)) {
            return null;
        }

        $room = $this->offered($branch, $card, $type->id, $hours);

        if ($room === null) {
            return null;
        }

        ['quote' => $quote, 'discount' => $discount] = $this->offer->quote($branch->id, $card, $type->id, $hours, $this->clock->now());

        return [
            'type' => ['id' => $type->id, 'name' => $type->name],
            'room' => $this->roomLabel($room),
            'hours' => $hours,
            'room_amount' => $quote->roomAmount->centavos(),
            'discount' => $discount === null ? null : [
                'name' => $discount->name,
                'amount' => $quote->discountAmount->centavos(),
                'text' => "{$discount->name} discount for {$hours}-hour stays.",
            ],
            'deposit' => ['name' => $branch->deposit_name, 'amount' => $quote->depositAmount->centavos()],
            'total' => $quote->totalToCollect()->centavos(),
        ];
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
     * Each active type with a price: its offered room (or full), hour options and free count.
     *
     * @return list<array{id: int, name: string, description: string|null, room: array{id: int, number: string, label: string}|null, full: bool, options: list<array{hours: int, amount: int}>, free: int}>
     */
    private function types(Branch $branch): array
    {
        if (! $branch->hourly_enabled) {
            return [];
        }

        $card = RateCard::forBranch($branch);
        $now = $this->clock->now();
        $out = [];

        $types = RoomType::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_active', true)->orderBy('id')->get();

        foreach ($types as $type) {
            $options = [];

            foreach ($this->offer->hours($card, $type->id) as $h) {
                try {
                    $options[] = ['hours' => $h, 'amount' => $this->offer->quote($branch->id, $card, $type->id, $h, $now)['quote']->roomAmount->centavos()];
                } catch (RateNotFound) {
                    continue;
                }
            }

            if ($options === []) {
                continue;
            }

            // Offered for the shortest stay; HoldRoom checks again for the hours chosen.
            $eligible = $this->rotation->order($this->rooms->kioskCandidates($branch->id, $type->id, $now, $this->period($branch, $card, $type->id, $options[0]['hours'])));
            $first = $eligible[0] ?? null;
            $room = $first === null ? null : Room::query()->withoutGlobalScopes()->find($first->id);

            $out[] = [
                'id' => $type->id,
                'name' => $type->name,
                'description' => $type->description,
                'room' => $room === null ? null : $this->roomLabel($room),
                'full' => $room === null,
                'options' => $options,
                'free' => count($eligible),
            ];
        }

        return $out;
    }

    private function offered(Branch $branch, RateCard $card, int $typeId, int $hours): ?Room
    {
        $next = $this->rotation->next($this->rooms->kioskCandidates($branch->id, $typeId, $this->clock->now(), $this->period($branch, $card, $typeId, $hours)));

        return $next === null ? null : Room::query()->withoutGlobalScopes()->find($next->id);
    }

    /** The latest a kiosk stay of these hours could end: started at the end of the hold. */
    private function period(Branch $branch, RateCard $card, int $typeId, int $hours): CarbonImmutable
    {
        $start = $this->clock->now()->addMinutes($branch->kiosk_hold_minutes);

        return $this->offer->quote($branch->id, $card, $typeId, $hours, $start)['quote']->expectedCheckoutAt;
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
