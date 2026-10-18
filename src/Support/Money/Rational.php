<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitInvoices\Support\Money;

use Brick\Math\BigInteger;
use InvalidArgumentException;

/**
 * An exact fraction on arbitrary-precision integers, so totals never touch floats:
 * `Rational::of('7.25')->times(Rational::of('1999'))->roundHalfUp()`. Only the
 * `BigInteger` operations every brick/math release has are used.
 *
 * @internal
 */
final class Rational
{
    private function __construct(
        private readonly BigInteger $numerator,
        private readonly BigInteger $denominator,
    ) {}

    /**
     * An integer, or a decimal string such as `"12.5"`, `"-0.0750"`.
     */
    public static function of(int|string|self $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_int($value)) {
            return new self(BigInteger::of($value), BigInteger::of(1));
        }

        $value = trim($value);

        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $value, $matches) !== 1 || ($matches[2] === '' && ($matches[3] ?? '') === '')) {
            throw new InvalidArgumentException("[{$value}] is not a decimal number.");
        }

        $fraction = $matches[3] ?? '';
        $digits = ltrim($matches[2].$fraction, '0');
        $numerator = BigInteger::of($digits === '' ? '0' : $digits);

        if ($matches[1] === '-') {
            $numerator = $numerator->negated();
        }

        return self::make($numerator, BigInteger::of(10)->power(strlen($fraction)));
    }

    public static function zero(): self
    {
        return new self(BigInteger::of(0), BigInteger::of(1));
    }

    public static function one(): self
    {
        return new self(BigInteger::of(1), BigInteger::of(1));
    }

    /** `rate / 100`: a percentage as a fraction. */
    public static function percent(int|string $rate): self
    {
        return self::of($rate)->dividedBy(self::of(100));
    }

    private static function make(BigInteger $numerator, BigInteger $denominator): self
    {
        if ($denominator->isZero()) {
            throw new InvalidArgumentException('Division by zero.');
        }

        if ($denominator->getSign() < 0) {
            $numerator = $numerator->negated();
            $denominator = $denominator->negated();
        }

        $gcd = $numerator->gcd($denominator);

        if (! $gcd->isZero() && ! $gcd->isEqualTo(1)) {
            $numerator = $numerator->quotient($gcd);
            $denominator = $denominator->quotient($gcd);
        }

        return new self($numerator, $denominator);
    }

    public function plus(int|string|self $that): self
    {
        $that = self::of($that);

        return self::make(
            $this->numerator->multipliedBy($that->denominator)->plus($that->numerator->multipliedBy($this->denominator)),
            $this->denominator->multipliedBy($that->denominator),
        );
    }

    public function minus(int|string|self $that): self
    {
        return $this->plus(self::of($that)->negated());
    }

    public function times(int|string|self $that): self
    {
        $that = self::of($that);

        return self::make($this->numerator->multipliedBy($that->numerator), $this->denominator->multipliedBy($that->denominator));
    }

    public function dividedBy(int|string|self $that): self
    {
        $that = self::of($that);

        return self::make($this->numerator->multipliedBy($that->denominator), $this->denominator->multipliedBy($that->numerator));
    }

    public function negated(): self
    {
        return new self($this->numerator->negated(), $this->denominator);
    }

    public function isZero(): bool
    {
        return $this->numerator->isZero();
    }

    public function sign(): int
    {
        return $this->numerator->getSign();
    }

    public function compareTo(int|string|self $that): int
    {
        return $this->minus($that)->sign();
    }

    /**
     * Rounded to an integer, halves away from zero (`2.5` → `3`, `-2.5` → `-3`).
     */
    public function roundHalfUp(): int
    {
        return $this->rounded()->toInt();
    }

    private function rounded(): BigInteger
    {
        // floor((2|n| + d) / 2d), with the sign put back.
        $rounded = $this->numerator->abs()->multipliedBy(2)->plus($this->denominator)->quotient($this->denominator->multipliedBy(2));

        return $this->numerator->getSign() < 0 ? $rounded->negated() : $rounded;
    }

    /**
     * A decimal string with `$scale` digits, halves away from zero.
     */
    public function toDecimal(int $scale): string
    {
        $factor = BigInteger::of(10)->power($scale);
        $scaled = $this->times(self::make($factor, BigInteger::of(1)))->rounded()->toString();

        if ($scale === 0) {
            return $scaled;
        }

        $negative = str_starts_with($scaled, '-');
        $digits = str_pad(ltrim($scaled, '-'), $scale + 1, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }
}
