<?php

declare(strict_types=1);

namespace App\Support;

use App\Casts\MoneyCast;
use Illuminate\Contracts\Database\Eloquent\Castable;
use InvalidArgumentException;
use JsonSerializable;
use OverflowException;
use Stringable;

/**
 * Money as integer centavos (TRD §3.8). Immutable. No floats anywhere.
 *
 * Serialises to its integer centavos, so Inertia props carry plain integers
 * that Vue formats with formatMoney().
 */
final class Money implements Castable, JsonSerializable, Stringable
{
    private function __construct(private readonly int $centavos) {}

    public static function ofCentavos(int $centavos): self
    {
        return new self($centavos);
    }

    /** Whole pesos: Money::ofPesos(350) is ₱350.00. */
    public static function ofPesos(int $pesos): self
    {
        return new self(self::checked($pesos * 100, 'ofPesos'));
    }

    /** Parse a typed amount such as "1,234.50" or "₱350" without floats. */
    public static function parse(string $amount): self
    {
        $clean = str_replace([',', ' ', '₱'], '', trim($amount));

        if (preg_match('/^(-)?(\d+)(?:\.(\d{1,2}))?$/', $clean, $m) !== 1) {
            throw new InvalidArgumentException("Not a money amount: {$amount}");
        }

        $centavos = self::checked(((int) $m[2]) * 100, 'parse') + (int) str_pad($m[3] ?? '0', 2, '0');

        return new self($m[1] === '-' ? -$centavos : $centavos);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** @param  iterable<self>  $amounts */
    public static function sum(iterable $amounts): self
    {
        $total = self::zero();

        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    public function centavos(): int
    {
        return $this->centavos;
    }

    public function plus(self $other): self
    {
        return new self(self::checked($this->centavos + $other->centavos, 'plus'));
    }

    public function minus(self $other): self
    {
        return new self(self::checked($this->centavos - $other->centavos, 'minus'));
    }

    public function times(int $factor): self
    {
        return new self(self::checked($this->centavos * $factor, 'times'));
    }

    /**
     * A percentage of this amount in basis points (10000 = 100 %, 2000 = 20 %),
     * rounded half away from zero to the centavo, with integer maths only.
     */
    public function percent(int $basisPoints): self
    {
        $product = self::checked($this->centavos * $basisPoints, 'percent');
        $quotient = intdiv($product, 10000);
        $remainder = $product % 10000;

        if (abs($remainder) * 2 >= 10000) {
            $quotient += $product < 0 ? -1 : 1;
        }

        return new self($quotient);
    }

    public function negate(): self
    {
        return new self(-$this->centavos);
    }

    public function abs(): self
    {
        return new self(abs($this->centavos));
    }

    public function isZero(): bool
    {
        return $this->centavos === 0;
    }

    public function isPositive(): bool
    {
        return $this->centavos > 0;
    }

    public function isNegative(): bool
    {
        return $this->centavos < 0;
    }

    public function equals(self $other): bool
    {
        return $this->centavos === $other->centavos;
    }

    public function greaterThan(self $other): bool
    {
        return $this->centavos > $other->centavos;
    }

    public function lessThan(self $other): bool
    {
        return $this->centavos < $other->centavos;
    }

    public function min(self $other): self
    {
        return $this->lessThan($other) ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->greaterThan($other) ? $this : $other;
    }

    /** Display format ₱1,234.00; negatives as −₱1,234.00 (TRD §1). */
    public function format(): string
    {
        $symbol = config('hotel.currency.symbol', '₱');
        $symbol = is_string($symbol) ? $symbol : '₱';
        $abs = abs($this->centavos);
        $pesos = number_format(intdiv($abs, 100));
        $cents = str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return ($this->centavos < 0 ? '−' : '').$symbol.$pesos.'.'.$cents;
    }

    public function jsonSerialize(): int
    {
        return $this->centavos;
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return class-string<MoneyCast>
     */
    public static function castUsing(array $arguments): string
    {
        return MoneyCast::class;
    }

    private static function checked(int|float $result, string $operation): int
    {
        if (! is_int($result)) {
            throw new OverflowException("Money {$operation} went past the integer range.");
        }

        return $result;
    }
}
