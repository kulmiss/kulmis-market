<?php

namespace Tests\Unit;

use App\Support\Money;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_basis_points(): void
    {
        $this->assertSame(75_000, Money::bpsFloor(500_000, 1_500));   // 15% of 5,000 ETB
        $this->assertSame(33, Money::bpsFloor(335, 1_000));            // floor
        $this->assertSame(34, Money::bpsRound(335, 1_000));            // half-up
        $this->assertSame(0, Money::bpsFloor(9, 100));
    }

    public function test_format_and_parse(): void
    {
        $this->assertSame('5,000', Money::format(500_000, false));
        $this->assertSame('1,234.50 ETB', Money::format(123_450));
        $this->assertSame('-0.05 ETB', Money::format(-5));
        $this->assertSame(123_450, Money::fromBirr('1,234.5'));
        $this->assertSame(500_000, Money::fromBirr(5000));
    }

    public function test_invalid_bps_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::bpsFloor(100, 10_001);
    }
}
