<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    public static function numbers(): array
    {
        return [
            'local with spaces' => ['0911 234 567', '+251911234567'],
            'local Safaricom' => ['0712345678', '+251712345678'],
            'no leading zero' => ['911234567', '+251911234567'],
            'country code' => ['251911234567', '+251911234567'],
            'plus and dashes' => ['+251-911-234-567', '+251911234567'],
            'brackets and dots' => ['(+251) 911.234.567', '+251911234567'],
            'already normal' => ['+251911234567', '+251911234567'],
            'Addis landline' => ['011 551 2345', null],
            'too short' => ['091123456', null],
            'too long' => ['09112345678', null],
            'foreign' => ['+14155552671', null],
            'letters' => ['09ABCDEFGH', null],
            'empty' => ['', null],
            'null' => [null, null],
            'double plus' => ['++251911234567', null],
            'other prefix 08' => ['0811234567', null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_ethiopian_mobile(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::ethiopianMobile($input));
    }

    public function test_mask(): void
    {
        $this->assertSame('+251******567', PhoneNumber::mask('+251911234567'));
        $this->assertSame('12345', PhoneNumber::mask('12345'));
    }
}
