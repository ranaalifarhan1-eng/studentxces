<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_to_cents_converts_exact_standard_decimals(): void
    {
        $this->assertSame(500025, Money::toCents('5000.25'));
        $this->assertSame(10, Money::toCents('0.10'));
        $this->assertSame(500000, Money::toCents('5000'));
        $this->assertSame(500050, Money::toCents('5000.5'));
        $this->assertSame(500050, Money::toCents('5000.50'));
        $this->assertSame(1, Money::toCents('0.01'));
        $this->assertSame(0, Money::toCents('0'));
        $this->assertSame(0, Money::toCents('0.00'));
        $this->assertSame(500000, Money::toCents(5000));
        $this->assertSame(0, Money::toCents(null));
        $this->assertSame(-2550, Money::toCents('-25.50'));
    }

    public function test_to_decimal_formats_cents_accurately(): void
    {
        $this->assertSame('5000.25', Money::toDecimal(500025));
        $this->assertSame('0.10', Money::toDecimal(10));
        $this->assertSame('5000.00', Money::toDecimal(500000));
        $this->assertSame('0.01', Money::toDecimal(1));
        $this->assertSame('0.00', Money::toDecimal(0));
        $this->assertSame('-25.50', Money::toDecimal(-2550));
    }

    public function test_rejects_malformed_financial_strings(): void
    {
        $invalidInputs = ['abc', '12.345', '--5', '12.3.4', '', '   ', '12,50', '$$100'];

        foreach ($invalidInputs as $invalid) {
            try {
                Money::toCents($invalid);
                $this->fail("Expected InvalidArgumentException for input: '{$invalid}'");
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_arithmetic_operations(): void
    {
        $this->assertSame('150.75', Money::add('100.50', '50.25'));
        $this->assertSame('50.25', Money::sub('100.50', '50.25'));
        $this->assertSame(30050, Money::sumToCents(['100.00', '100.25', '100.25']));
    }
}
