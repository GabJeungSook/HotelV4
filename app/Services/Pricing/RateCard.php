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
     * @param  array<int, array<int, array{id: int, amount: Money}>>  $hourly  room type id → hours → rate
     * @param  array<int, array{id: int, amount: Money}>  $overnight  room type id → rate
     * @param  array<int, Money>  $extension  hours → extension rate
     * @param  array<int, string>  $typeNames  room type id → name
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
    ) {}

    /** Load the card of a branch from its rows. */
    public static function forBranch(Branch $branch): self
    {
        $hourly = [];
        $overnight = [];

        foreach (Rate::query()->withoutGlobalScopes()->where('branch_id', $branch->id)->get() as $rate) {
            $entry = ['id' => $rate->id, 'amount' => $rate->amount ?? Money::zero()];

            if ($rate->stay_kind === StayKind::Overnight) {
                $overnight[$rate->room_type_id] = $entry;
            } else {
                $hourly[$rate->room_type_id][$rate->hours] = $entry;
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
        );
    }

    public function typeName(int $roomTypeId): string
    {
        return $this->typeNames[$roomTypeId] ?? 'this room type';
    }

    /** @return array{id: int, amount: Money}|null */
    public function hourlyRate(int $roomTypeId, int $hours): ?array
    {
        return $this->hourly[$roomTypeId][$hours] ?? null;
    }

    /** @return array{id: int, amount: Money}|null */
    public function overnightRate(int $roomTypeId): ?array
    {
        return $this->overnight[$roomTypeId] ?? null;
    }

    public function extensionRate(int $hours): ?Money
    {
        return $this->extension[$hours] ?? null;
    }
}
