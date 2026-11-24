<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\ExtensionRule;
use App\Enums\StayKind;
use App\Models\Branch;
use App\Models\ExtensionRate;
use App\Models\Rate;
use App\Models\RoomType;
use App\Support\Clock;
use App\Support\Money;

/**
 * Everything PricingService needs to know about one branch's prices, as plain values:
 * its rates, extension rates and stay settings. PricingService reads nothing else, so it
 * can be unit-tested without a database (TRD §3.3).
 */
final class RateCard
{
    /**
     * The override buckets mirror $hourly/$overnight but are keyed by floor id or room id, and are
     * empty unless the branch turns floor & room rates on. A lookup resolves room → floor → type.
     *
     * @param  array<int, array<int, array{id: int, amount: Money}>>  $hourly  room type id → hours → rate
     * @param  array<int, array{id: int, amount: Money}>  $overnight  room type id → rate
     * @param  array<int, Money>  $extension  hours → extension rate
     * @param  array<int, string>  $typeNames  room type id → name
     * @param  array<int, array<int, array{id: int, amount: Money}>>  $floorHourly  floor id → hours → rate
     * @param  array<int, array{id: int, amount: Money}>  $floorOvernight  floor id → rate
     * @param  array<int, array<int, array{id: int, amount: Money}>>  $roomHourly  room id → hours → rate
     * @param  array<int, array{id: int, amount: Money}>  $roomOvernight  room id → rate
     */
    public function __construct(
        public readonly array $hourly,
        public readonly array $overnight,
        public readonly array $extension,
        public readonly array $typeNames,
        public readonly bool $hourlyEnabled = true,
        public readonly bool $overnightEnabled = true,
        public readonly bool $longStayEnabled = true,
        public readonly int $longStayMaxDays = 31,
        public readonly string $overnightCheckoutTime = '12:00:00',
        public readonly ExtensionRule $extensionRule = ExtensionRule::Cycle,
        public readonly int $extensionCycleHours = 24,
        public readonly ?Money $extensionPerHourAmount = null,
        public readonly bool $depositEnabled = true,
        public readonly ?Money $depositAmount = null,
        public readonly string $timezone = 'Asia/Manila',
        public readonly array $floorHourly = [],
        public readonly array $floorOvernight = [],
        public readonly array $roomHourly = [],
        public readonly array $roomOvernight = [],
    ) {}

    /** Load the card of a branch from its rows. */
    public static function forBranch(Branch $branch): self
    {
        $hourly = [];
        $overnight = [];
        $floorHourly = [];
        $floorOvernight = [];
        $roomHourly = [];
        $roomOvernight = [];
        $overrides = $branch->floor_room_rates_enabled;

        foreach (Rate::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->get() as $rate) {
            $entry = ['id' => $rate->id, 'amount' => $rate->amount ?? Money::zero()];
            $overnightKind = $rate->stay_kind === StayKind::Overnight;

            if ($overrides && $rate->room_id !== null) {
                $overnightKind ? $roomOvernight[$rate->room_id] = $entry : $roomHourly[$rate->room_id][$rate->hours] = $entry;
            } elseif ($overrides && $rate->floor_id !== null) {
                $overnightKind ? $floorOvernight[$rate->floor_id] = $entry : $floorHourly[$rate->floor_id][$rate->hours] = $entry;
            } elseif ($rate->room_type_id !== null) {
                $overnightKind ? $overnight[$rate->room_type_id] = $entry : $hourly[$rate->room_type_id][$rate->hours] = $entry;
            }
        }

        $extension = [];
        foreach (ExtensionRate::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->get() as $rate) {
            $extension[$rate->hours] = $rate->amount ?? Money::zero();
        }

        $typeNames = [];
        foreach (RoomType::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->get(['id', 'name']) as $type) {
            $typeNames[$type->id] = $type->name;
        }

        return new self(
            hourly: $hourly,
            overnight: $overnight,
            extension: $extension,
            typeNames: $typeNames,
            hourlyEnabled: $branch->hourly_enabled,
            overnightEnabled: $branch->overnight_enabled,
            longStayEnabled: $branch->long_stay_enabled,
            longStayMaxDays: $branch->long_stay_max_days,
            overnightCheckoutTime: $branch->overnight_checkout_time,
            extensionRule: $branch->extension_rule,
            extensionCycleHours: $branch->extension_cycle_hours,
            extensionPerHourAmount: $branch->extension_per_hour_amount,
            depositEnabled: $branch->deposit_enabled,
            depositAmount: $branch->deposit_amount,
            timezone: Clock::displayTimezone(),
            floorHourly: $floorHourly,
            floorOvernight: $floorOvernight,
            roomHourly: $roomHourly,
            roomOvernight: $roomOvernight,
        );
    }

    public function typeName(int $roomTypeId): string
    {
        return $this->typeNames[$roomTypeId] ?? 'this room type';
    }

    /**
     * The hourly rate for a stay, most-specific-first: the room's own rate, else its floor's flat
     * rate, else the room type's base rate. Passing no room/floor gives the type rate as before.
     *
     * @return array{id: int, amount: Money}|null
     */
    public function hourlyRate(int $roomTypeId, int $hours, ?int $roomId = null, ?int $floorId = null): ?array
    {
        return ($roomId !== null ? ($this->roomHourly[$roomId][$hours] ?? null) : null)
            ?? ($floorId !== null ? ($this->floorHourly[$floorId][$hours] ?? null) : null)
            ?? $this->hourly[$roomTypeId][$hours]
            ?? null;
    }

    /** @return array{id: int, amount: Money}|null */
    public function overnightRate(int $roomTypeId, ?int $roomId = null, ?int $floorId = null): ?array
    {
        return ($roomId !== null ? ($this->roomOvernight[$roomId] ?? null) : null)
            ?? ($floorId !== null ? ($this->floorOvernight[$floorId] ?? null) : null)
            ?? $this->overnight[$roomTypeId]
            ?? null;
    }

    public function extensionRate(int $hours): ?Money
    {
        return $this->extension[$hours] ?? null;
    }
}
