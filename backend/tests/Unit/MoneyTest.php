<?php

namespace Tests\Unit;

use App\Domain\Accounting\ValueObjects\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_exact_decimal_arithmetic_without_floating_point_errors(): void
    {
        $m1 = Money::of('0.10');
        $m2 = Money::of('0.20');
        $sum = $m1->add($m2);

        $this->assertSame('0.30', $sum->getAmount());
        $this->assertTrue($sum->equals(Money::of('0.30')));
    }

    public function test_division_with_half_up_rounding_at_001(): void
    {
        // AT-001: 100000 / 3 = 33333.33
        $money = Money::of('100000.00');
        $result = $money->divide(3);

        $this->assertSame('33333.33', $result->getAmount());
    }

    public function test_division_half_up_boundary(): void
    {
        // 10.00 / 4 = 2.50
        $this->assertSame('2.50', Money::of('10.00')->divide(4)->getAmount());

        // 2.555 -> 2.56
        $this->assertSame('2.56', Money::roundHalfUp('2.555', 2));

        // 2.554 -> 2.55
        $this->assertSame('2.55', Money::roundHalfUp('2.554', 2));
    }

    public function test_multiplication_half_up(): void
    {
        $money = Money::of('10.55');
        $result = $money->multiply('2.5'); // 26.375 -> 26.38

        $this->assertSame('26.38', $result->getAmount());
    }

    public function test_subtraction(): void
    {
        $m1 = Money::of('100.50');
        $m2 = Money::of('45.25');
        $diff = $m1->subtract($m2);

        $this->assertSame('55.25', $diff->getAmount());
    }

    public function test_comparison_and_zero_checks(): void
    {
        $zero = Money::zero();
        $positive = Money::of('100.00');
        $negative = Money::of('-50.00');

        $this->assertTrue($zero->isZero());
        $this->assertTrue($positive->isPositive());
        $this->assertTrue($negative->isNegative());
        $this->assertTrue($positive->isGreaterThan($zero));
        $this->assertTrue($negative->isLessThan($zero));
    }

    public function test_format_rupiah(): void
    {
        $money = Money::of('72125396.20');
        $this->assertSame('Rp 72.125.396,20', $money->formatRupiah());
    }

    public function test_division_by_zero_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('100.00')->divide(0);
    }

    public function test_invalid_string_format_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('abc');
    }
}
