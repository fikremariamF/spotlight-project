<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public static function validAmounts(): array
    {
        return [
            ['0', 0], ['1', 100], ['1500', 150000], ['1500.5', 150050], ['1500.50', 150050],
            ['1500.05', 150005], ['0.01', 1], ['9999999999.99', 999999999999], [' 25 ', 2500], [19.99, 1999], [7, 700],
        ];
    }

    #[DataProvider('validAmounts')]
    public function test_converts_birr_to_santim(string|int|float $birr, int $santim): void
    {
        $this->assertSame($santim, Money::toSantim($birr));
    }

    public static function invalidAmounts(): array
    {
        return [['-5'], ['1,500'], ['1500.505'], ['abc'], [''], ['1e5'], ['.5'], ['12345678901']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_malformed_amounts(string $birr): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toSantim($birr);
    }

    public function test_formats_santim_as_birr(): void
    {
        $this->assertSame('1500.50', Money::toBirr(150050));
        $this->assertSame('0.05', Money::toBirr(5));
        $this->assertSame('0.00', Money::toBirr(0));
        $this->assertNull(Money::toBirr(null));
        // Credits on returns go out as negative amounts.
        $this->assertSame('-1500.50', Money::toBirr(-150050));
        $this->assertSame('-0.05', Money::toBirr(-5));
        $this->assertSame('-1.00', Money::toBirr(-100));
    }

    public function test_round_trip_is_lossless(): void
    {
        foreach ([1, 99, 100, 101, 123456789, 999999999999] as $santim) {
            $this->assertSame($santim, Money::toSantim(Money::toBirr($santim)));
        }
    }
}
