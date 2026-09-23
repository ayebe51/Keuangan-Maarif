<?php

namespace App\Domain\Accounting\ValueObjects;

use InvalidArgumentException;
use Stringable;

/**
 * Immutable Money Value Object ensuring strict monetary precision (DECIMAL 18,2).
 * Operates purely on BCMath string arithmetic with explicit HALF-UP rounding.
 * Floating point arithmetic is strictly prohibited to prevent precision loss.
 */
final class Money implements Stringable
{
    private string $amount;

    public function __construct(string|int|float|null $amount = '0.00')
    {
        $this->amount = self::normalize($amount);
    }

    public static function of(string|int|float|null $amount): self
    {
        return new self($amount);
    }

    public static function zero(): self
    {
        return new self('0.00');
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function add(Money $other): self
    {
        $result = bcadd($this->amount, $other->amount, 2);
        return new self($result);
    }

    public function subtract(Money $other): self
    {
        $result = bcsub($this->amount, $other->amount, 2);
        return new self($result);
    }

    public function multiply(string|int|float $multiplier): self
    {
        $multStr = is_float($multiplier) ? sprintf('%.6f', $multiplier) : (string) $multiplier;
        $raw = bcmul($this->amount, $multStr, 6);
        return new self(self::roundHalfUp($raw, 2));
    }

    public function divide(string|int|float $divisor): self
    {
        $divStr = is_float($divisor) ? sprintf('%.6f', $divisor) : (string) $divisor;
        if (bccomp($divStr, '0', 6) === 0) {
            throw new InvalidArgumentException('Division by zero is not permitted in Money operations.');
        }

        $raw = bcdiv($this->amount, $divStr, 6);
        return new self(self::roundHalfUp($raw, 2));
    }

    public function equals(Money $other): bool
    {
        return bccomp($this->amount, $other->amount, 2) === 0;
    }

    public function compare(Money $other): int
    {
        return bccomp($this->amount, $other->amount, 2);
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0.00', 2) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0.00', 2) > 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0.00', 2) < 0;
    }

    public function isGreaterThan(Money $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function isLessThan(Money $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function formatRupiah(): string
    {
        $parts = explode('.', $this->amount);
        $integerPart = number_format((float)$parts[0], 0, ',', '.');
        $decimalPart = $parts[1] ?? '00';

        return 'Rp ' . $integerPart . ',' . $decimalPart;
    }

    public function __toString(): string
    {
        return $this->amount;
    }

    private static function normalize(string|int|float|null $val): string
    {
        if ($val === null || $val === '') {
            return '0.00';
        }

        if (is_float($val)) {
            $str = sprintf('%.4f', $val);
            return self::roundHalfUp($str, 2);
        }

        $clean = trim((string)$val);
        // If scientific notation or dirty format
        if (!preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
            throw new InvalidArgumentException("Invalid monetary amount string format: '{$val}'");
        }

        return self::roundHalfUp($clean, 2);
    }

    public static function roundHalfUp(string $number, int $precision = 2): string
    {
        $isNegative = str_starts_with($number, '-');
        $abs = ltrim($number, '-');

        if (!str_contains($abs, '.')) {
            $abs .= '.' . str_repeat('0', $precision + 4);
        } else {
            $abs .= str_repeat('0', 4);
        }

        // Half-up: add 0.005 for precision 2, then truncate with bcadd(..., 0, precision)
        $halfAdder = '0.' . str_repeat('0', $precision) . '5';
        $added = bcadd($abs, $halfAdder, $precision + 2);

        // Format to exact precision
        $dotPos = strpos($added, '.');
        $integerPart = substr($added, 0, $dotPos);
        $fractionPart = substr($added, $dotPos + 1, $precision);
        $fractionPart = str_pad($fractionPart, $precision, '0');

        $result = $integerPart . '.' . $fractionPart;

        return ($isNegative && bccomp($result, '0.00', $precision) !== 0) ? '-' . $result : $result;
    }
}
