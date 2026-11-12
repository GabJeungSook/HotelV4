<?php

declare(strict_types=1);

use App\Enums\DiscountKind;
use App\Enums\ExtensionRule;
use App\Enums\StayKind;
use App\Exceptions\DiscountNotAllowed;
use App\Exceptions\InvalidStayOption;
use App\Exceptions\RateNotFound;
use App\Services\Pricing\DiscountTerms;
use App\Services\Pricing\RateCard;
use App\Services\PricingService;
use App\Support\Money;
use Carbon\CarbonImmutable;

/*
| TRD §4.2–4.4 and the Pricing tests of TRD §10. The card holds the seeded rates
| (DATABASE_SCHEMA §5): Suite 18 h is missing on purpose (screen 52).
*/

const STANDARD = 1;
const DELUXE = 2;
const SUITE = 3;

/** @param array<string, mixed> $overrides */
function card(array $overrides = []): RateCard
{
    $rates = [
        STANDARD => [6 => 35000, 12 => 50000, 18 => 65000, 24 => 80000],
        DELUXE => [6 => 45000, 12 => 65000, 18 => 85000, 24 => 100000],
        SUITE => [6 => 60000, 12 => 90000, 24 => 140000],
    ];
    $hourly = [];
    $id = 1;
    foreach ($rates as $type => $byHours) {
        foreach ($byHours as $hours => $amount) {
            $hourly[$type][$hours] = ['id' => $id++, 'amount' => Money::ofCentavos($amount)];
        }
    }
    $overnight = [
        STANDARD => ['id' => 50, 'amount' => Money::ofCentavos(75000)],
        DELUXE => ['id' => 51, 'amount' => Money::ofCentavos(95000)],
        SUITE => ['id' => 52, 'amount' => Money::ofCentavos(130000)],
    ];

    return new RateCard(...array_merge([
        'hourly' => $hourly,
        'overnight' => $overnight,
        'extension' => [3 => Money::ofCentavos(15000), 6 => Money::ofCentavos(25000), 12 => Money::ofCentavos(40000), 24 => Money::ofCentavos(70000)],
        'typeNames' => [STANDARD => 'Standard', DELUXE => 'Deluxe', SUITE => 'Suite'],
        'depositAmount' => Money::ofCentavos(20000),
        'extensionPerHourAmount' => Money::ofCentavos(5000),
    ], $overrides));
}

/** A Manila wall-clock time as UTC. */
function manila(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Asia/Manila')->utc();
}

function rateId(int $type, int $hours): int
{
    return card()->hourlyRate($type, $hours)['id'] ?? 0;
}

beforeEach(function (): void {
    $this->pricing = new PricingService;
    $this->at = manila('2026-11-12 09:00');
});

// Stay pricing — hourly 6/12/18/24 per type

it('prices an hourly stay from the rate of its type and hours', function (int $type, int $hours, int $amount): void {
    $quote = $this->pricing->quoteStay(card(), $type, StayKind::Hourly, $hours, $this->at);

    expect($quote->roomAmount->centavos())->toBe($amount)
        ->and($quote->expectedCheckoutAt->equalTo($this->at->addHours($hours)))->toBeTrue()
        ->and($quote->cycleHoursUsed)->toBe($hours)
        ->and($quote->depositAmount->centavos())->toBe(20000)
        ->and($quote->totalToCollect()->centavos())->toBe($amount + 20000);
})->with([
    'Standard 6 h' => [STANDARD, 6, 35000], 'Standard 12 h' => [STANDARD, 12, 50000],
    'Standard 18 h' => [STANDARD, 18, 65000], 'Standard 24 h' => [STANDARD, 24, 80000],
    'Deluxe 6 h' => [DELUXE, 6, 45000], 'Deluxe 12 h' => [DELUXE, 12, 65000],
    'Deluxe 18 h' => [DELUXE, 18, 85000], 'Deluxe 24 h' => [DELUXE, 24, 100000],
    'Suite 6 h' => [SUITE, 6, 60000], 'Suite 12 h' => [SUITE, 12, 90000], 'Suite 24 h' => [SUITE, 24, 140000],
]);

it('describes the stay for the folio line', function (): void {
    expect($this->pricing->quoteStay(card(), DELUXE, StayKind::Hourly, 12, $this->at)->description)->toBe('Deluxe 12 hrs');
});

// Overnight 1 and 3 nights, check-in before and after midnight

it('prices one night checked in before midnight to the next day check-out time', function (): void {
    $quote = $this->pricing->quoteStay(card(), DELUXE, StayKind::Overnight, 1, manila('2026-11-11 22:30'));

    expect($quote->roomAmount->centavos())->toBe(95000)
        ->and($quote->expectedCheckoutAt->equalTo(manila('2026-11-12 12:00')))->toBeTrue()
        ->and($quote->cycleHoursUsed)->toBe(0);
});

it('prices one night checked in after midnight to the same day check-out time', function (): void {
    $quote = $this->pricing->quoteStay(card(), DELUXE, StayKind::Overnight, 1, manila('2026-11-12 01:15'));

    expect($quote->roomAmount->centavos())->toBe(95000)
        ->and($quote->expectedCheckoutAt->equalTo(manila('2026-11-12 12:00')))->toBeTrue();
});

it('prices three nights checked in before midnight', function (): void {
    $quote = $this->pricing->quoteStay(card(), STANDARD, StayKind::Overnight, 3, manila('2026-11-11 21:00'));

    expect($quote->roomAmount->centavos())->toBe(225000)
        ->and($quote->description)->toBe('Standard 3 nights')
        ->and($quote->expectedCheckoutAt->equalTo(manila('2026-11-14 12:00')))->toBeTrue();
});

it('prices three nights checked in after midnight', function (): void {
    $quote = $this->pricing->quoteStay(card(), STANDARD, StayKind::Overnight, 3, manila('2026-11-12 02:00'));

    expect($quote->roomAmount->centavos())->toBe(225000)
        ->and($quote->expectedCheckoutAt->equalTo(manila('2026-11-14 12:00')))->toBeTrue();
});

// Long stay 1 and 31 days, 32 refused

it('prices a long stay of 1 day at the 24-hour rate', function (): void {
    $quote = $this->pricing->quoteStay(card(), DELUXE, StayKind::LongStay, 1, $this->at);

    expect($quote->roomAmount->centavos())->toBe(100000)
        ->and($quote->expectedCheckoutAt->equalTo($this->at->addHours(24)))->toBeTrue()
        ->and($quote->cycleHoursUsed)->toBe(0);
});

it('prices a long stay of 31 days at the 24-hour rate times 31', function (): void {
    $quote = $this->pricing->quoteStay(card(), DELUXE, StayKind::LongStay, 31, $this->at);

    expect($quote->roomAmount->centavos())->toBe(3100000)
        ->and($quote->expectedCheckoutAt->equalTo($this->at->addDays(31)))->toBeTrue();
});

it('refuses a long stay of 32 days', function (): void {
    $this->pricing->quoteStay(card(), DELUXE, StayKind::LongStay, 32, $this->at);
})->throws(InvalidStayOption::class, 'A long stay is 1 to 31 days.');

it('refuses a stay kind the branch has switched off', function (): void {
    $this->pricing->quoteStay(card(['overnightEnabled' => false]), DELUXE, StayKind::Overnight, 1, $this->at);
})->throws(InvalidStayOption::class);

// Discounts

it('subtracts a fixed discount in centavos from the room amount only', function (): void {
    $promo = new DiscountTerms('Promo', DiscountKind::Fixed, 5000, false, [rateId(DELUXE, 6)]);
    $quote = $this->pricing->quoteStay(card(), DELUXE, StayKind::Hourly, 6, $this->at, $promo);

    expect($quote->discountAmount->centavos())->toBe(5000)
        ->and($quote->netRoomAmount()->centavos())->toBe(40000)
        ->and($quote->depositAmount->centavos())->toBe(20000)
        ->and($quote->totalToCollect()->centavos())->toBe(60000);
});

it('rounds a percent discount half up to the centavo', function (): void {
    $odd = card(['hourly' => [STANDARD => [6 => ['id' => 9, 'amount' => Money::ofCentavos(12345)]]]]);
    $ten = new DiscountTerms('Ten', DiscountKind::Percent, 10);
    $senior = new DiscountTerms('Senior citizen', DiscountKind::Percent, 20);

    expect($this->pricing->quoteStay($odd, STANDARD, StayKind::Hourly, 6, $this->at, $ten)->discountAmount->centavos())->toBe(1235)
        ->and($this->pricing->quoteStay(card(), DELUXE, StayKind::Hourly, 12, $this->at, $senior)->discountAmount->centavos())->toBe(13000);
});

it('never discounts below zero', function (): void {
    $big = new DiscountTerms('Big', DiscountKind::Fixed, 999999);
    $quote = $this->pricing->quoteStay(card(), STANDARD, StayKind::Hourly, 6, $this->at, $big);

    expect($quote->discountAmount->centavos())->toBe(35000)
        ->and($quote->netRoomAmount()->isZero())->toBeTrue()
        ->and($quote->totalToCollect()->centavos())->toBe(20000);
});

it('refuses a discount limited to some rates on another rate', function (): void {
    $promo = new DiscountTerms('Promo', DiscountKind::Fixed, 5000, false, [rateId(DELUXE, 6)]);

    $this->pricing->quoteStay(card(), DELUXE, StayKind::Hourly, 12, $this->at, $promo);
})->throws(DiscountNotAllowed::class, 'Promo cannot be used on Deluxe 12 hrs.');

it('leaves out the deposit when the branch has deposits switched off', function (): void {
    $quote = $this->pricing->quoteStay(card(['depositEnabled' => false]), DELUXE, StayKind::Hourly, 12, $this->at);

    expect($quote->depositAmount->isZero())->toBeTrue()->and($quote->totalToCollect()->centavos())->toBe(65000);
});

// Missing rate

it('throws RateNotFound for a missing rate and never prices it at zero', function (): void {
    $this->pricing->quoteStay(card(), SUITE, StayKind::Hourly, 18, $this->at);
})->throws(RateNotFound::class, 'There is no rate for Suite 18 hrs.');

it('treats a zero rate as missing', function (): void {
    $zero = card(['overnight' => [DELUXE => ['id' => 1, 'amount' => Money::zero()]]]);

    $this->pricing->quoteStay($zero, DELUXE, StayKind::Overnight, 1, $this->at);
})->throws(RateNotFound::class);

// Extension pricing (TRD §4.3, worked example of mockup 12)

it('extends within the cycle at the extension rate', function (): void {
    $quote = $this->pricing->quoteExtension(card(), DELUXE, StayKind::Hourly, 12, 6);

    expect($quote->amount->centavos())->toBe(25000)->and($quote->cycleHoursUsed)->toBe(18);
});

it('extends up to exactly the cycle end at the extension rate', function (): void {
    $quote = $this->pricing->quoteExtension(card(), DELUXE, StayKind::Hourly, 12, 12);

    expect($quote->amount->centavos())->toBe(40000)->and($quote->cycleHoursUsed)->toBe(24);
});

it('extends from exactly the cycle end at the regular rate and starts a new cycle', function (): void {
    $quote = $this->pricing->quoteExtension(card(), DELUXE, StayKind::Hourly, 24, 6);

    expect($quote->amount->centavos())->toBe(45000)->and($quote->cycleHoursUsed)->toBe(6);
});

it('extends across the cycle end: the rest of the cycle at the extension rate, the overflow at the regular rate', function (): void {
    $quote = $this->pricing->quoteExtension(card(), DELUXE, StayKind::Hourly, 12, 24);

    expect($quote->amount->centavos())->toBe(105000)
        ->and($quote->cycleHoursUsed)->toBe(12)
        ->and(array_map(fn (array $p): int => $p['amount']->centavos(), $quote->parts))->toBe([40000, 65000]);
});

it('extends by the hour under the per_hour rule and leaves the cycle alone', function (): void {
    $quote = $this->pricing->quoteExtension(card(['extensionRule' => ExtensionRule::PerHour]), DELUXE, StayKind::Hourly, 12, 3);

    expect($quote->amount->centavos())->toBe(15000)->and($quote->cycleHoursUsed)->toBe(12);
});

it('extends by the block rate under the block rule and leaves the cycle alone', function (): void {
    $quote = $this->pricing->quoteExtension(card(['extensionRule' => ExtensionRule::Block]), DELUXE, StayKind::Hourly, 12, 24);

    expect($quote->amount->centavos())->toBe(70000)->and($quote->cycleHoursUsed)->toBe(12);
});

it('extends overnight and long stays by the block rule whatever the setting', function (): void {
    $quote = $this->pricing->quoteExtension(card(), DELUXE, StayKind::Overnight, 0, 6);

    expect($quote->amount->centavos())->toBe(25000)->and($quote->cycleHoursUsed)->toBe(0);
});

it('throws RateNotFound when an extension rate is missing', function (): void {
    $this->pricing->quoteExtension(card(), DELUXE, StayKind::Hourly, 12, 2);
})->throws(RateNotFound::class, 'There is no rate for a 2-hour extension.');

it('throws RateNotFound when the regular rate for the overflow is missing', function (): void {
    // Suite: 6 hours used + 24 crosses the cycle; the 6-hour overflow needs Suite 6 h (exists),
    // but 18 used + 24 needs the 18-hour Suite rate, which is missing.
    $this->pricing->quoteExtension(card(), SUITE, StayKind::Hourly, 18, 24);
})->throws(RateNotFound::class, 'Suite 18 hrs');

it('throws RateNotFound for per_hour with no per-hour amount', function (): void {
    $this->pricing->quoteExtension(card(['extensionRule' => ExtensionRule::PerHour, 'extensionPerHourAmount' => Money::zero()]), DELUXE, StayKind::Hourly, 12, 3);
})->throws(RateNotFound::class);

it('offers only the extension hours whose every rate exists', function (): void {
    $hours = array_map(fn ($q): int => $q->hours, $this->pricing->extensionOptions(card(), SUITE, StayKind::Hourly, 18));

    // 3 and 6 stay inside the cycle; 12 crosses it with a 6-hour overflow (Suite 6 h exists);
    // 24 needs Suite 18 h, which is missing.
    expect($hours)->toBe([3, 6, 12]);
});

it('offers nothing when no extension rate exists', function (): void {
    expect($this->pricing->extensionOptions(card(['extension' => []]), DELUXE, StayKind::Hourly, 12))->toBe([]);
});

// Transfer pricing (TRD §4.4)

it('prices a transfer upgrade as the new type price minus what was charged', function (): void {
    $quote = $this->pricing->quoteTransfer(card(), DELUXE, StayKind::Hourly, 12, Money::ofCentavos(50000));

    expect($quote->newPrice->centavos())->toBe(65000)->and($quote->difference->centavos())->toBe(15000);
});

it('prices a transfer downgrade as a credit', function (): void {
    $quote = $this->pricing->quoteTransfer(card(), STANDARD, StayKind::Hourly, 12, Money::ofCentavos(65000));

    expect($quote->difference->centavos())->toBe(-15000);
});

it('prices a transfer to a type of the same price at zero', function (): void {
    expect($this->pricing->quoteTransfer(card(), DELUXE, StayKind::Hourly, 12, Money::ofCentavos(65000))->difference->isZero())->toBeTrue();
});

it('prices an overnight transfer for every night', function (): void {
    $quote = $this->pricing->quoteTransfer(card(), DELUXE, StayKind::Overnight, 2, Money::ofCentavos(150000));

    expect($quote->difference->centavos())->toBe(40000);
});

it('throws RateNotFound when the new type has no rate for the stay option', function (): void {
    $this->pricing->quoteTransfer(card(), SUITE, StayKind::Hourly, 18, Money::ofCentavos(85000));
})->throws(RateNotFound::class);
