<?php

declare(strict_types=1);

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

it('holds integer centavos', function (): void {
    expect(Money::ofCentavos(35_050)->centavos())->toBe(35_050)
        ->and(Money::ofPesos(350)->centavos())->toBe(35_000)
        ->and(Money::zero()->isZero())->toBeTrue();
});

it('adds, subtracts and multiplies without floats', function (): void {
    $room = Money::ofPesos(350);
    $extension = Money::ofCentavos(12_550);

    expect($room->plus($extension)->centavos())->toBe(47_550)
        ->and($room->minus($extension)->centavos())->toBe(22_450)
        ->and($room->times(3)->centavos())->toBe(105_000)
        ->and($extension->minus($room)->isNegative())->toBeTrue()
        ->and(Money::sum([$room, $extension, Money::ofCentavos(1)])->centavos())->toBe(47_551);
});

it('is immutable', function (): void {
    $a = Money::ofPesos(100);
    $a->plus(Money::ofPesos(50));

    expect($a->centavos())->toBe(10_000);
});

it('takes a percentage in basis points, rounding half away from zero', function (): void {
    expect(Money::ofPesos(750)->percent(2000)->centavos())->toBe(15_000) // 20 % of 750
        ->and(Money::ofCentavos(125)->percent(1000)->centavos())->toBe(13) // 12.5 → 13
        ->and(Money::ofCentavos(124)->percent(1000)->centavos())->toBe(12) // 12.4 → 12
        ->and(Money::ofCentavos(-125)->percent(1000)->centavos())->toBe(-13);
});

it('compares amounts', function (): void {
    $a = Money::ofPesos(10);
    $b = Money::ofPesos(20);

    expect($a->lessThan($b))->toBeTrue()
        ->and($b->greaterThan($a))->toBeTrue()
        ->and($a->equals(Money::ofCentavos(1_000)))->toBeTrue()
        ->and($a->min($b))->toBe($a)
        ->and($a->max($b))->toBe($b)
        ->and($a->negate()->abs()->equals($a))->toBeTrue();
});

it('formats as ₱1,234.00', function (): void {
    expect(Money::ofCentavos(123_400)->format())->toBe('₱1,234.00')
        ->and(Money::ofCentavos(5)->format())->toBe('₱0.05')
        ->and(Money::ofCentavos(-35_000)->format())->toBe('−₱350.00')
        ->and((string) Money::ofCentavos(100_000_000)->format())->toBe('₱1,000,000.00');
});

it('parses typed amounts without floats', function (string $typed, int $centavos): void {
    expect(Money::parse($typed)->centavos())->toBe($centavos);
})->with([
    ['350', 35_000],
    ['1,234.5', 123_450],
    ['₱1,234.56', 123_456],
    ['0.07', 7],
    ['-20', -2_000],
]);

it('refuses text that is not an amount', function (string $typed): void {
    Money::parse($typed);
})->with(['abc', '1.234', '', '12.3.4'])->throws(InvalidArgumentException::class);

it('refuses to overflow', function (): void {
    Money::ofCentavos(PHP_INT_MAX)->plus(Money::ofCentavos(1));
})->throws(OverflowException::class);

it('serialises to integer centavos for Inertia props', function (): void {
    expect(json_encode(['total' => Money::ofCentavos(47_550)]))->toBe('{"total":47550}');
});

it('casts columns to and from Money', function (): void {
    $model = new class extends Model
    {
        protected $casts = ['room_amount' => Money::class, 'counted_cash' => MoneyCast::class];
    };

    $model->room_amount = Money::ofPesos(350);
    $model->counted_cash = 12_345;

    expect($model->getAttributes())->toBe(['room_amount' => 35_000, 'counted_cash' => 12_345])
        ->and($model->room_amount)->toBeInstanceOf(Money::class)
        ->and($model->room_amount->centavos())->toBe(35_000)
        ->and($model->counted_cash->centavos())->toBe(12_345);

    $model->setRawAttributes(['room_amount' => '75000', 'counted_cash' => null]);

    expect($model->room_amount->centavos())->toBe(75_000)
        ->and($model->counted_cash)->toBeNull();
});

it('refuses to store a float in a money column', function (): void {
    $model = new class extends Model
    {
        protected $casts = ['room_amount' => Money::class];
    };

    $model->room_amount = 350.5;
})->throws(InvalidArgumentException::class);
