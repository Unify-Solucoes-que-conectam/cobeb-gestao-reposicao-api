<?php

namespace Tests\Unit;

use App\Support\BrazilianPhoneNumber;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BrazilianPhoneNumberTest extends TestCase
{
    #[DataProvider('validNumbers')]
    public function test_it_normalizes_brazilian_numbers(string $input, string $national, string $international): void
    {
        $phone = new BrazilianPhoneNumber();

        $this->assertSame($national, $phone->national($input));
        $this->assertSame($international, $phone->international($input));
    }

    public static function validNumbers(): array
    {
        return [
            ['(37) 99824-7669', '37998247669', '5537998247669'],
            ['+55 (37) 99824-7669', '37998247669', '5537998247669'],
            ['3732590820', '3732590820', '553732590820'],
            ['553732590820', '3732590820', '553732590820'],
        ];
    }

    public function test_it_rejects_an_invalid_length(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new BrazilianPhoneNumber())->national('99824-7669');
    }
}
