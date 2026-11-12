<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DiscountKind;
use App\Enums\ExtensionRule;
use App\Enums\StayKind;
use App\Exceptions\DiscountNotAllowed;
use App\Exceptions\InvalidStayOption;
use App\Exceptions\RateNotFound;
use App\Services\Pricing\DiscountTerms;
use App\Services\Pricing\ExtensionQuote;
use App\Services\Pricing\RateCard;
use App\Services\Pricing\StayQuote;
use App\Services\Pricing\TransferQuote;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The only place prices are worked out (CLAUDE.md rule 6, TRD §4.2–4.4). Pure: values in,
 * values out, no database, no clock. A missing rate throws RateNotFound; nothing is ever
 * priced at zero by default.
 */
final class PricingService
{
    /** Hours a single hourly stay or extension can be (rates_hours_chk, extension_rates_chk). */
    private const MAX_HOURS = 24;

    /**
     * @param  int  $quantity  hours (hourly), nights (overnight) or days (long stay)
     */
    public function quoteStay(RateCard $card, int $roomTypeId, StayKind $kind, int $quantity, CarbonImmutable $checkInAt, ?DiscountTerms $discount = null): StayQuote
    {
        $type = $card->typeName($roomTypeId);

        [$rate, $roomAmount, $description, $checkout, $cycle] = match ($kind) {
            StayKind::Hourly => $this->hourly($card, $roomTypeId, $type, $quantity, $checkInAt),
            StayKind::Overnight => $this->overnight($card, $roomTypeId, $type, $quantity, $checkInAt),
            StayKind::LongStay => $this->longStay($card, $roomTypeId, $type, $quantity, $checkInAt),
        };

        $discountAmount = Money::zero();

        if ($discount !== null) {
            if (! $discount->allows($rate)) {
                throw DiscountNotAllowed::onRate($discount->name, $description);
            }

            $discountAmount = $this->discountOn($roomAmount, $discount);
        }

        $deposit = $card->depositEnabled ? ($card->depositAmount ?? Money::zero()) : Money::zero();

        return new StayQuote($kind, $quantity, $rate, $description, $roomAmount, $discountAmount, $deposit, $checkout, $cycle);
    }

    /** A discount on the room amount only: never on the deposit, never below zero (TRD §4.2). */
    public function discountOn(Money $roomAmount, DiscountTerms $discount): Money
    {
        $amount = match ($discount->kind) {
            DiscountKind::Fixed => Money::ofCentavos($discount->value),
            // value is a whole percent; Money::percent takes basis points and rounds half up.
            DiscountKind::Percent => $roomAmount->percent($discount->value * 100),
        };

        return $amount->min($roomAmount)->max(Money::zero());
    }

    /**
     * Price of extending a stay by $hours (TRD §4.3). Overnight and long stays always use
     * the block rule; only hourly stays follow the branch's extension_rule.
     */
    public function quoteExtension(RateCard $card, int $roomTypeId, StayKind $kind, int $cycleHoursUsed, int $hours): ExtensionQuote
    {
        if ($hours < 1 || $hours > self::MAX_HOURS) {
            throw InvalidStayOption::because('An extension is 1 to '.self::MAX_HOURS.' hours.');
        }

        $rule = $kind === StayKind::Hourly ? $card->extensionRule : ExtensionRule::Block;

        if ($rule === ExtensionRule::Block) {
            $amount = $this->extension($card, $hours);

            return new ExtensionQuote($hours, $amount, $cycleHoursUsed, [['label' => "Extension {$hours} hrs", 'amount' => $amount]]);
        }

        return $rule === ExtensionRule::PerHour
            ? $this->perHour($card, $cycleHoursUsed, $hours)
            : $this->cycle($card, $roomTypeId, $cycleHoursUsed, $hours);
    }

    /**
     * The extension options to offer: only hours whose every needed rate exists (TRD §4.3).
     * An empty list means the missing-rate alert (52).
     *
     * @param  list<int>|null  $hours  candidates; by default the branch's extension rate hours
     * @return list<ExtensionQuote>
     */
    public function extensionOptions(RateCard $card, int $roomTypeId, StayKind $kind, int $cycleHoursUsed, ?array $hours = null): array
    {
        $candidates = $hours ?? array_keys($card->extension);
        sort($candidates);
        $options = [];

        foreach ($candidates as $h) {
            try {
                $options[] = $this->quoteExtension($card, $roomTypeId, $kind, $cycleHoursUsed, $h);
            } catch (RateNotFound|InvalidStayOption) {
                continue;
            }
        }

        return $options;
    }

    /**
     * The difference of moving a stay to another room type (TRD §4.4): the same stay option
     * at the new type's rates, minus the room line plus earlier transfer differences.
     * Discounts are not re-applied; the expected check-out does not change.
     */
    public function quoteTransfer(RateCard $card, int $newRoomTypeId, StayKind $kind, int $quantity, Money $alreadyCharged): TransferQuote
    {
        $type = $card->typeName($newRoomTypeId);
        $newPrice = match ($kind) {
            StayKind::Hourly => $this->rateOrFail($card->hourlyRate($newRoomTypeId, $quantity), "{$type} {$quantity} hrs")['amount'],
            StayKind::Overnight => $this->rateOrFail($card->overnightRate($newRoomTypeId), "{$type} overnight")['amount']->times($quantity),
            StayKind::LongStay => $this->rateOrFail($card->hourlyRate($newRoomTypeId, 24), "{$type} long stay (24-hour rate)")['amount']->times($quantity),
        };

        return new TransferQuote($newPrice, $alreadyCharged, $newPrice->minus($alreadyCharged));
    }

    /** @return array{int, Money, string, CarbonImmutable, int} */
    private function hourly(RateCard $card, int $typeId, string $type, int $hours, CarbonImmutable $at): array
    {
        if (! $card->hourlyEnabled) {
            throw InvalidStayOption::because('Hourly stays are not sold at this branch.');
        }

        if ($hours < 1 || $hours > self::MAX_HOURS) {
            throw InvalidStayOption::because('An hourly stay is 1 to '.self::MAX_HOURS.' hours.');
        }

        $description = "{$type} {$hours} hrs";
        $rate = $this->rateOrFail($card->hourlyRate($typeId, $hours), $description);

        return [$rate['id'], $rate['amount'], $description, $at->addHours($hours), $hours];
    }

    /** @return array{int, Money, string, CarbonImmutable, int} */
    private function overnight(RateCard $card, int $typeId, string $type, int $nights, CarbonImmutable $at): array
    {
        if (! $card->overnightEnabled) {
            throw InvalidStayOption::because('Overnight stays are not sold at this branch.');
        }

        if ($nights < 1) {
            throw InvalidStayOption::because('An overnight stay is at least 1 night.');
        }

        $rate = $this->rateOrFail($card->overnightRate($typeId), "{$type} overnight");
        $description = $nights === 1 ? "{$type} overnight" : "{$type} {$nights} nights";

        return [$rate['id'], $rate['amount']->times($nights), $description, $this->overnightCheckout($card, $at, $nights), 0];
    }

    /** @return array{int, Money, string, CarbonImmutable, int} */
    private function longStay(RateCard $card, int $typeId, string $type, int $days, CarbonImmutable $at): array
    {
        if (! $card->longStayEnabled) {
            throw InvalidStayOption::because('Long stays are not sold at this branch.');
        }

        if ($days < 1 || $days > $card->longStayMaxDays) {
            throw InvalidStayOption::because("A long stay is 1 to {$card->longStayMaxDays} days.");
        }

        $rate = $this->rateOrFail($card->hourlyRate($typeId, 24), "{$type} long stay (24-hour rate)");
        $description = $days === 1 ? "{$type} long stay 1 day" : "{$type} long stay {$days} days";

        return [$rate['id'], $rate['amount']->times($days), $description, $at->addHours(24 * $days), 0];
    }

    /** The N-th next occurrence of the branch's check-out time after check-in, in branch local time. */
    private function overnightCheckout(RateCard $card, CarbonImmutable $at, int $nights): CarbonImmutable
    {
        $local = $at->setTimezone($card->timezone);
        $first = CarbonImmutable::parse($local->format('Y-m-d').' '.$card->overnightCheckoutTime, $card->timezone);

        if ($first->lessThanOrEqualTo($local)) {
            $first = $first->addDay();
        }

        return $first->addDays($nights - 1)->setTimezone('UTC');
    }

    private function perHour(RateCard $card, int $used, int $hours): ExtensionQuote
    {
        $perHour = $card->extensionPerHourAmount;

        if ($perHour === null || ! $perHour->isPositive()) {
            throw RateNotFound::for('the per-hour extension');
        }

        $amount = $perHour->times($hours);

        return new ExtensionQuote($hours, $amount, $used, [['label' => "{$hours} hrs × {$perHour->format()}", 'amount' => $amount]]);
    }

    private function cycle(RateCard $card, int $typeId, int $used, int $hours): ExtensionQuote
    {
        $cycle = $card->extensionCycleHours;
        $type = $card->typeName($typeId);

        // Still inside the cycle: the extension rate.
        if ($used + $hours <= $cycle) {
            $amount = $this->extension($card, $hours);

            return new ExtensionQuote($hours, $amount, $used + $hours, [['label' => "Extension {$hours} hrs", 'amount' => $amount]]);
        }

        // The cycle is used up: a new cycle starts at the regular rate.
        if ($used >= $cycle) {
            $amount = $this->rateOrFail($card->hourlyRate($typeId, $hours), "{$type} {$hours} hrs")['amount'];

            return new ExtensionQuote($hours, $amount, $hours % $cycle, [['label' => "{$type} {$hours} hrs (new cycle)", 'amount' => $amount]]);
        }

        // Crossing the cycle end: the hours left in the cycle at the extension rate, the overflow at the regular rate.
        $left = $cycle - $used;
        $overflow = $used + $hours - $cycle;
        $inCycle = $this->extension($card, $left);
        $regular = $this->rateOrFail($card->hourlyRate($typeId, $overflow), "{$type} {$overflow} hrs")['amount'];

        return new ExtensionQuote($hours, $inCycle->plus($regular), $overflow, [
            ['label' => "Extension {$left} hrs (rest of the cycle)", 'amount' => $inCycle],
            ['label' => "{$type} {$overflow} hrs (new cycle)", 'amount' => $regular],
        ]);
    }

    private function extension(RateCard $card, int $hours): Money
    {
        return $card->extensionRate($hours) ?? throw RateNotFound::for("a {$hours}-hour extension");
    }

    /**
     * @param  array{id: int, amount: Money}|null  $rate
     * @return array{id: int, amount: Money}
     */
    private function rateOrFail(?array $rate, string $what): array
    {
        if ($rate === null || ! $rate['amount']->isPositive()) {
            throw RateNotFound::for($what);
        }

        return $rate;
    }
}
